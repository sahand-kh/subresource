<?php


namespace Basilisk\SubResource;


use Illuminate\Support\ServiceProvider;

class SubResourceServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->bind('SubResource', function ($app){
            return new SubResource();
        });
    }
}
