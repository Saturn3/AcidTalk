<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LinkPreview extends Model
{
    protected $fillable = ['message_id', 'url', 'kind', 'provider', 'title', 'description', 'image', 'site_name'];
}
