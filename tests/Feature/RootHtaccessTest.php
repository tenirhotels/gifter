<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Запасной .htaccess в корне: если у сайта нельзя выбрать корневую папку public, всё равно отдаётся только public/. */
class RootHtaccessTest extends TestCase
{
    public function test_root_htaccess_sends_everything_into_public_and_blocks_dotfiles_and_listing(): void
    {
        $rules = (string) file_get_contents(base_path('.htaccess'));

        $this->assertStringContainsString('Options -Indexes', $rules);
        $this->assertStringContainsString('RewriteRule ^(.*)$ public/$1 [L]', $rules);
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !^/public/', $rules);
        // Запрет файлов и папок с точкой стоит раньше переписывания в public/.
        $this->assertLessThan(strpos($rules, 'public/$1'), strpos($rules, '(^|/)\.(?!well-known/) - [F,L]'));
    }

    public function test_public_htaccess_also_forbids_dotfiles_and_dumps(): void
    {
        $rules = (string) file_get_contents(public_path('.htaccess'));

        $this->assertStringContainsString('(^|/)\.(?!well-known/) - [F,L]', $rules);
        $this->assertStringContainsString('(sqlite|sql|zip|bak|log|env)$', $rules);
        $this->assertStringContainsString('-Indexes', $rules);
    }
}
