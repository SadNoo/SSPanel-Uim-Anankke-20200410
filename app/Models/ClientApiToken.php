<?php

namespace App\Models;

class ClientApiToken extends Model
{
    protected $connection = 'default';
    protected $table = 'client_api_tokens';

    protected $casts = array(
        'user_id' => 'int',
        'created_at' => 'int',
        'expires_at' => 'int',
        'revoked_at' => 'int',
    );
}
