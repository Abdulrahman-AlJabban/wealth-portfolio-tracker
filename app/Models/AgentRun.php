<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRun extends Model
{
    protected $fillable = ['user_id', 'portfolio_id', 'run_id', 'tool', 'input', 'outcome', 'error'];

    protected $casts = ['input' => 'array'];
}
