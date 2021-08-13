<?php


namespace Basilisk\SubResource\Facades;


use Illuminate\Support\Facades\Facade;

class SubResource extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'SubResource';
    }
}
