<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\AssistantTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Adapter only: all logic, authorization and auditing live in AssistantTools::execute().
 */
final class GetPortfolioSummaryTool implements Tool
{
    public function __construct(private AssistantTools $tools) {}

    public function name(): string
    {
        return 'get_portfolio_summary';
    }

    public function description(): string
    {
        return 'Current holdings of this portfolio with quantity, latest price, value, cost and profit, plus total value, total cost and total profit. Use it for any question about what the portfolio holds or is worth.';
    }

    public function handle(Request $request): string
    {
        return $this->tools->execute($this->name(), $request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
