<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RoomType extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'price'];

    public function rooms(){
        return $this->hasMany(Room::class);
    }
}
