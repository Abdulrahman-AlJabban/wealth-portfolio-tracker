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
final class GetAllocationTool implements Tool
{
    public function __construct(private AssistantTools $tools) {}

    public function name(): string
    {
        return 'get_allocation';
    }

    public function description(): string
    {
        return 'How the portfolio value is split, as percentages by asset and by asset type (stock, crypto, real_estate, cash). Use it for allocation, diversification or rebalancing questions.';
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
