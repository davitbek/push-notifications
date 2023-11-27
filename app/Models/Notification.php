<?php

namespace App\Models;

class Notification extends BaseModel
{
    const STATUS_IN_QUEUE = 0;
    const STATUS_STARTED = 1;
    const STATUS_FINISHED = 2;
}