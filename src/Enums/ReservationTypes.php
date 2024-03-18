<?php

namespace Reach\StatamicResrv\Enums;

enum ReservationTypes: string
{
    case NORMAL = 'normal';
    case PARENT = 'parent';
    case CHILD = 'child';
}