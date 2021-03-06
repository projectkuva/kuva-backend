<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{

  public function photo() {
    return $this->belongsTo('App\Models\Photo');
  }
  public function user() {
  	return $this->BelongsTo('App\Models\User');
  }
}
