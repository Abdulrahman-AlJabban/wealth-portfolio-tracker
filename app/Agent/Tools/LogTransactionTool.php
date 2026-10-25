<?php

declare(strict_types=1);

namespace App\Agent\Tools;

use App\Agent\AssistantTools;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

/**
 * Adapter only: all logic, authorization and auditing live in AssistantTools::execute(),
 * which writes through the LogTransaction Action.
 */
final class LogTransactionTool implements Tool
{
    public function __construct(private AssistantTools $tools) {}

    public function name(): string
    {
        return 'log_transaction';
    }

    public function description(): string
    {
        return 'Log a buy, sell, deposit or withdrawal in this portfolio, dated now. Only call it after the user clearly asked to log it and gave the asset, type, quantity and price.';
    }

    public function handle(Request $request): string
    {
        return $this->tools->execute($this->name(), $request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'asset_symbol' => $schema->string()->description('Asset symbol, for example "BTC" or "AAPL".')->required(),
            'type' => $schema->string()->description('One of: buy, sell, deposit, withdrawal.')->required(),
            'quantity' => $schema->string()->description('Positive decimal as a string, for example "0.5".')->required(),
            'price' => $schema->string()->description('Unit price as a decimal string, for example "65000".')->required(),
            'note' => $schema->string()->description('Optional short note.'),
        ];
    }
}
