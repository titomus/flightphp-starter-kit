<?php

declare(strict_types=1);

final class TemplateRenderer
{
    public function render(string $template, array $vars): string
    {
        return preg_replace_callback('/{{\s*([a-zA-Z0-9_]+)\s*}}/', static function (array $matches) use ($vars): string {
            return array_key_exists($matches[1], $vars) ? (string) $vars[$matches[1]] : $matches[0];
        }, $template) ?? $template;
    }
}
