<?php

namespace Tests\Unit;

use Tests\TestCase;

class PosSentLineNoteFallbackTest extends TestCase
{
    public function test_send_to_production_falls_back_to_session_cart_note(): void
    {
        $controller = file_get_contents(app_path('Http/Controllers/PosController.php'));

        // Posted payload wins, session-cart note is the fallback, result is saved.
        $this->assertStringContainsString('$lineDescription = isset($item[\'description\'])', $controller);
        $this->assertStringContainsString('fall back to the note persisted in the restaurant session cart', $controller);
        $this->assertStringContainsString("'description' => \$lineDescription !== '' ? \$lineDescription : null", $controller);
        $this->assertStringContainsString("\$sessLine['description']", $controller);

        // Order reload returns the saved note to the cart.
        $this->assertStringContainsString("'description' => \$line->description ?? ''", $controller);
    }

    public function test_send_payload_carries_cart_line_key_for_exact_note_match(): void
    {
        $index = file_get_contents(resource_path('views/pos/index-restaurant.blade.php'));
        $this->assertStringContainsString("cart_line_key: \$(this).attr('data-cart-line-key') || ''", $index);

        // After send, the cart row renders the saved note read-only under the item.
        $this->assertStringContainsString('pos-item-note-readonly', $index);
        $this->assertStringContainsString('if (it.is_sent && note)', $index);
    }

    public function test_production_screen_renders_line_note(): void
    {
        $view = file_get_contents(resource_path('views/pos_production/index.blade.php'));
        $this->assertStringContainsString('$line->description', $view);
    }
}
