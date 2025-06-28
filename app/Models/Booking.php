<?php
// filepath: d:\Learing\ITE YEAR3\re-exam\demo-exam\app\Models\Booking.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'room_type_id',
        'room_id',
        'check_in_date',
        'check_out_date',
        'guests',
        'status',
        'total_price',
        'payment_status',
        'payment_method',
    ];

    protected $casts = [
        'check_in_date' => 'date',
        'check_out_date' => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function roomType()
    {
        return $this->belongsTo(RoomType::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}
