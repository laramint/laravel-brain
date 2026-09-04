<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

abstract class BaseEntity extends Model
{
    protected $fillable = ['name'];
}
