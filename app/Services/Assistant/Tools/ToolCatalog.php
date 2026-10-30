<?php

namespace App\Services\Assistant\Tools;

use App\Services\Assistant\Actions\ActionContext;
use App\Services\Assistant\Actions\ChangeTool;
use App\Services\Assistant\Actions\Tools as Change;
use Illuminate\Contracts\Container\Container;

/**
 * Las herramientas del asistente, como el catálogo de Equipo: para darle algo nuevo que consultar se escribe su clase y
 * se agrega aquí. El orden no cambia entre preguntas (así OpenAI reutiliza lo que ya leyó y cobra menos).
 */
class ToolCatalog
{
    /** @var array<int, class-string<Tool>> */
    public const TOOLS = [
        SalesTool::class,
        MoneyTool::class,
        FunnelTool::class,
        LossesTool::class,
        TeamTool::class,
        PersonTool::class,
        FindPersonTool::class,
        GoalsTool::class,
        AlertsTool::class,
        CalendarTool::class,
        // Paso 3: órdenes, inventario, comisiones, cierres, caja, gastos, tasas, usuarios, agencias y ciudades
        FindOrderTool::class,
        OrderHistoryTool::class,
        StockTool::class,
        LowStockTool::class,
        IncomingTool::class,
        ReplenishmentTool::class,
        CommissionsTool::class,
        ClosuresTool::class,
        CashTool::class,
        ExpensesTool::class,
        RatesTool::class,
        UsersTool::class,
        RoutingTool::class,
        // Paso 5: cambios, siempre con la tarjeta de Confirmar (Actions\ChangeTool)
        Change\CalendarNoteTool::class,
        Change\CalendarMarkTool::class,
        Change\RatesChangeTool::class,
        Change\BankTool::class,
        Change\ExpenseTool::class,
        Change\RecurringExpenseTool::class,
        Change\RecurringPaymentTool::class,
    ];

    /** @var array<string, Tool>|null */
    private ?array $tools = null;

    public function __construct(private Container $app)
    {
    }

    /** Un catálogo con estas herramientas y ninguna otra (para las pruebas). */
    public static function of(Tool ...$tools): self
    {
        $catalog = new self(new \Illuminate\Container\Container());
        $catalog->tools = [];
        foreach ($tools as $tool) {
            $catalog->tools[$tool->name()] = $tool;
        }

        return $catalog;
    }

    /** @return array<string, Tool> por nombre */
    public function all(): array
    {
        if ($this->tools === null) {
            $this->tools = [];
            foreach (self::TOOLS as $class) {
                $tool = $this->app->make($class);
                $this->tools[$tool->name()] = $tool;
            }
        }

        return $this->tools;
    }

    public function get(string $name): ?Tool
    {
        return $this->all()[$name] ?? null;
    }

    /** Las herramientas para una respuesta: las que cambian algo saben para quién proponen (ver Actions\ActionDesk). */
    public function forAnswer(ActionContext $context): self
    {
        $copy = clone $this;
        $copy->tools = array_map(fn (Tool $t) => $t instanceof ChangeTool ? $t->withContext($context) : $t, $this->all());

        return $copy;
    }

    /** Lo que ve la IA de cada herramienta (formato de funciones de la Responses API, estricto). */
    public function definitions(): array
    {
        return array_values(array_map(fn (Tool $t) => [
            'type' => 'function',
            'name' => $t->name(),
            'description' => $t->description(),
            'parameters' => $t->parameters(),
            'strict' => true,
        ], $this->all()));
    }
}
