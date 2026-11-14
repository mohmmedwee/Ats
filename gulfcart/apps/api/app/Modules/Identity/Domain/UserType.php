<?php

namespace App\Modules\Identity\Domain;

enum UserType: string
{
    case Customer = 'customer';
    case Staff = 'staff';
}
