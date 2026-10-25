<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Which user and portfolio an assistant conversation belongs to. See the create_assistant_threads migration.
 */
class AssistantThread extends Model
{
    protected $fillable = ['user_id', 'portfolio_id', 'conversation_id'];
}
