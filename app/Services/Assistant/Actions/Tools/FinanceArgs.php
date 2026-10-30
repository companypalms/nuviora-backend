<?php

namespace App\Services\Assistant\Actions\Tools;

use App\Models\ExpenseCategory;
use App\Models\FinancialAccount;
use App\Models\RecurringExpense;
use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Lookup;
use App\Services\Assistant\Tools\ToolProblem;
use App\Services\Finance\ExchangeRates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Lo que comparten las herramientas de gastos del asistente: encontrar la categoría, la cuenta o el gasto fijo por su
 * nombre (si hay dudas, la IA pregunta), los montos en palabras y el comprobante que vino en el chat.
 */
final class FinanceArgs
{
    /** Las fotos y los PDF que acepta Finanzas como comprobante (sin GIF). */
    private const RECEIPT_MIMES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public static function category(?string $name): ExpenseCategory
    {
        $all = ExpenseCategory::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();

        return ExpenseCategory::findOrFail(self::pick($name, $all, 'categoría', 'Categorías: '));
    }

    /** Una cuenta de la caja de donde sale el dinero (no el efectivo de una agencia). */
    public static function account(?string $name): FinancialAccount
    {
        $all = FinancialAccount::where('is_active', true)->where('kind', '!=', FinancialAccount::AGENCY_CASH)->orderBy('name')
            ->get()->mapWithKeys(fn ($a) => [$a->id => $a->name])->all();
        if (trim((string) $name) === '') {
            throw new ToolProblem('¿De qué cuenta salió el dinero? Cuentas: ' . implode(', ', $all) . '.');
        }

        return FinancialAccount::findOrFail(self::pick($name, $all, 'cuenta', 'Cuentas: '));
    }

    /** "Banesco (VES)"; si el nombre ya dice la moneda, solo el nombre. */
    public static function accountLabel(FinancialAccount $account): string
    {
        return str_contains(mb_strtoupper($account->name), $account->currency) ? $account->name : "{$account->name} ({$account->currency})";
    }

    public static function recurring(?string $name): RecurringExpense
    {
        $all = RecurringExpense::where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();

        return RecurringExpense::findOrFail(self::pick($name, $all, 'gasto fijo', 'Gastos fijos: '));
    }

    /** "$15,50", "Bs 1.200,00 (a Bs 40,00 por dólar: $30,00)", "3,00 USDT". */
    public static function money(float $amount, string $currency, ?float $rate = null): string
    {
        $n = fn (float $v) => number_format($v, 2, ',', '.');

        return match ($currency) {
            'VES' => 'Bs ' . $n($amount) . ($rate ? ' (a Bs ' . $n($rate) . ' por dólar: $' . $n(round($amount / $rate, 2)) . ')' : ''),
            'USDT' => $n($amount) . ' USDT',
            'EUR' => '€' . $n($amount),
            default => '$' . $n($amount),
        };
    }

    /** En bolívares hace falta la tasa: si no la dijo, se le pregunta con las de hoy. */
    public static function rateFor(string $currency, mixed $rate): ?float
    {
        if ($currency !== 'VES') {
            return null;
        }
        if (is_numeric($rate) && (float) $rate > 0) {
            return (float) $rate;
        }
        $today = app(ExchangeRates::class)->current();
        $shown = array_filter([
            'BCV' => $today['bcv_usd'] ?? null,
            'Binance' => $today['binance_usd'] ?? null,
        ]);

        throw new ToolProblem('Es en bolívares: ¿a qué tasa lo cuento?' . ($shown ? ' Hoy: ' . implode(' · ', array_map(fn ($k, $v) => "{$k} " . number_format($v, 2, ',', '.'), array_keys($shown), $shown)) . '.' : ''));
    }

    /** AAAA-MM-DD, o hoy si no la dijo. */
    public static function date(mixed $value, ActionContext $context, string $what = 'la fecha'): string
    {
        if ($value === null || $value === '') {
            return $context->now->toDateString();
        }
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
            throw new ToolProblem("{$what} tiene que ser AAAA-MM-DD.");
        }

        return CarbonImmutable::parse($value)->toDateString();
    }

    /**
     * El archivo N del mensaje del dueño como comprobante: foto JPG, PNG o WEBP, o PDF.
     * @return array{path: string, name: string, extension: string}|null
     */
    public static function receipt(mixed $number, ActionContext $context): ?array
    {
        if ($number === null) {
            return null;
        }
        $file = $context->file((int) $number);
        if ($file === null) {
            throw new ToolProblem("El dueño no mandó un archivo número {$number} en este mensaje.");
        }
        $extension = self::RECEIPT_MIMES[$file['mime'] ?? ''] ?? null;
        if ($extension === null) {
            throw new ToolProblem("\"{$file['name']}\" no sirve como comprobante: tiene que ser una foto (JPG, PNG o WEBP) o un PDF.");
        }
        if (!Storage::disk('local')->exists($file['path'])) {
            throw new ToolProblem("\"{$file['name']}\" ya no está en el servidor: que lo vuelva a mandar.");
        }

        return ['path' => $file['path'], 'name' => (string) $file['name'], 'extension' => $extension];
    }

    /** Copia el comprobante del chat a la carpeta de Finanzas (el del chat se queda donde está). */
    public static function storeReceipt(?array $receipt, string $folder): ?string
    {
        if ($receipt === null) {
            return null;
        }
        $path = $folder . '/' . Str::random(40) . '.' . $receipt['extension'];
        Storage::disk('local')->copy($receipt['path'], $path);

        return $path;
    }

    /** El que corresponde al nombre; si no hay o hay varios parecidos, se lo dice a la IA con la lista. */
    private static function pick(?string $name, array $all, string $what, string $listLabel): int
    {
        // Lookup::match deja uno solo cuando es claramente el mejor (por ejemplo, el nombre exacto)
        $found = Lookup::match((string) $name, $all, 5);
        if (count($found) === 1) {
            return $found[0]['id'];
        }
        $list = $listLabel . implode(', ', $found !== [] ? array_column($found, 'name') : $all) . '.';
        throw new ToolProblem(($found !== [] ? "Hay varias con ese nombre ({$what}): " : "No encontré esa {$what}. ") . $list . ' Pregúntale cuál.');
    }
}
