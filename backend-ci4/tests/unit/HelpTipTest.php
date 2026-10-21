<?php

use App\Libraries\Glossary;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * The (i) help button: a keyboard-focusable button labelled "More info", with
 * its pop-up text escaped. The pop-up itself is started by assets/js/help-tip.js.
 *
 * @internal
 */
final class HelpTipTest extends CIUnitTestCase
{
    public function testRendersAnAccessibleButton(): void
    {
        $html = help_tip('Safety stock', 'Extra units for busy days.');

        $this->assertStringContainsString('<button type="button"', $html);
        $this->assertStringContainsString('aria-label="More info"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('class="info-tip help-tip"', $html);
        $this->assertStringContainsString('data-help-title="Safety stock"', $html);
        $this->assertStringContainsString('Extra units for busy days.', html_entity_decode($html));
    }

    public function testBulletsAndParagraphsAreBuilt(): void
    {
        $bullets    = html_entity_decode(help_tip('A, B and C', ['First point', 'Second point']));
        $paragraphs = html_entity_decode(help_tip('Two parts', "One.\n\nTwo."));

        $this->assertStringContainsString('<ul class="help-list"><li>First point</li><li>Second point</li></ul>', $bullets);
        $this->assertStringContainsString('<p>One.</p><p>Two.</p>', $paragraphs);
    }

    public function testTextIsEscaped(): void
    {
        $html = help_tip('<script>alert(1)</script>', '<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img src=x', $html);
    }

    public function testGlossaryTermsUseTheSameButton(): void
    {
        $html = Glossary::labelWithTip('reorder_at');

        $this->assertStringContainsString('help-tip', $html);
        $this->assertStringContainsString('Reorder', $html);
        $this->assertStringContainsString('DSS', Glossary::label('dss'));
        $this->assertStringContainsString('Decision Support System', html_entity_decode(Glossary::tip('dss')));
    }
}
