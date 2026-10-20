<?php

namespace App\Support;

/** Ссылки публичной витрины с текущим языком (?lang=): язык не теряется при переходах, даже если cookie отключены. */
final class ShopUrl
{
    /** @param  array<string, mixed>|object  $parameters */
    public static function route(string $name, array|object $parameters = [], ?string $fragment = null): string
    {
        $url = route($name, is_array($parameters) ? $parameters + ['lang' => app()->getLocale()] : [$parameters, 'lang' => app()->getLocale()]);

        return $fragment ? $url.'#'.$fragment : $url;
    }
}
