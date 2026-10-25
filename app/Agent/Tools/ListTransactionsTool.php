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
final class ListTransactionsTool implements Tool
{
    public function __construct(private AssistantTools $tools) {}

    public function name(): string
    {
        return 'list_transactions';
    }

    public function description(): string
    {
        return 'The latest transactions in this portfolio, newest first: date, type, symbol, quantity, price and whether a user or the assistant logged it.';
    }

    public function handle(Request $request): string
    {
        return $this->tools->execute($this->name(), $request->all());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->string()->description('How many transactions to return, from "1" to "20". Default "10".'),
            'symbol' => $schema->string()->description('Only this asset, for example "BTC". Omit for all assets.'),
        ];
    }
}
