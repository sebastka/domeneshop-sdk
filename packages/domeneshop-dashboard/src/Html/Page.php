<?php

declare(strict_types=1);

namespace Sebastka\Domeneshop\Dashboard\Html;

use Sebastka\Domeneshop\Dashboard\Exception\PageUnavailableException;
use Sebastka\Domeneshop\Dashboard\Exception\UnexpectedPageException;

/**
 * One parsed dashboard page, with the few lookups the parsers need.
 *
 * Uses PHP's own HTML5 parser (`Dom\HTMLDocument`) rather than libxml. That
 * matters here: the dashboard nests `<form>` directly inside `<table>`, which is
 * invalid, and parsers disagree about how to repair it. The HTML5 algorithm is
 * the one browsers follow, so what this sees is what the browser sees. It does
 * mean form controls are not reliably descendants of their `<form>`, so lookups
 * go by control name across the document, or by table row — never by form.
 *
 * Every lookup that the caller depends on throws {@see UnexpectedPageException}
 * when it finds nothing, so a markup change surfaces as an error rather than as
 * an empty value.
 *
 * @internal
 */
final class Page
{
    private function __construct(
        private readonly \Dom\HTMLDocument $document,
        public readonly string $path,
    ) {
    }

    /**
     * Parse a page and confirm it is the one that was asked for.
     *
     * Every editable dashboard page carries a hidden `edit` input naming
     * itself. When a page is not offered for a domain, the dashboard does not
     * say so — it quietly serves the domain overview instead. Both cases are
     * told apart here so parsers never mistake one page for another.
     *
     * @param string $edit The page's `edit` value, e.g. `ns` or `dnssec`.
     *
     * @throws PageUnavailableException when the dashboard served the overview instead.
     * @throws UnexpectedPageException  when the page is neither.
     */
    public static function parse(string $html, string $path, string $edit): self
    {
        $page = new self(\Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR), $path);

        if ($page->document->querySelector(\sprintf('input[name="edit"][value="%s"]', $edit)) !== null) {
            return $page;
        }

        $tab = $page->document->querySelector('.TabContainer .AdminTab.selected');
        if ($tab !== null && self::clean($tab->textContent) === 'Oversikt') {
            throw PageUnavailableException::servedOverview($edit, $path);
        }

        throw UnexpectedPageException::missing(\sprintf('the "%s" page marker', $edit), $path);
    }

    /** The first element matching $selector, or an exception naming $what. */
    public function one(string $selector, string $what): \Dom\Element
    {
        return $this->document->querySelector($selector)
            ?? throw UnexpectedPageException::missing($what, $this->path);
    }

    /** @return list<\Dom\Element> */
    public function all(string $selector, ?\Dom\Element $within = null): array
    {
        return array_values(iterator_to_array(($within ?? $this->document)->querySelectorAll($selector)));
    }

    /**
     * The value of a form control, wherever it sits in the document.
     *
     * Text and hidden inputs give their `value`; selects give the `value` of the
     * selected option. Returns null when the control is absent, or when a
     * select has nothing selected — a browser would submit its first option
     * then, but that is a default, not data.
     */
    public function control(string $name): ?string
    {
        $control = $this->document->querySelector(\sprintf('[name="%s"]', $name));

        return $control === null ? null : self::valueOf($control);
    }

    /** Whether a checkbox is present and ticked; null when it is absent. */
    public function checkbox(string $name): ?bool
    {
        $box = $this->document->querySelector(\sprintf('input[type="checkbox"][name="%s"]', $name));

        return $box?->hasAttribute('checked');
    }

    public static function valueOf(\Dom\Element $control): ?string
    {
        if (strtolower($control->tagName) === 'select') {
            return $control->querySelector('option[selected]')?->getAttribute('value');
        }

        return $control->getAttribute('value') ?? '';
    }

    /**
     * An element's visible text, without the buttons the dashboard puts beside
     * values ("Endre eier", "Endre betalingskontakt") and with `&nbsp;` padding
     * collapsed.
     */
    public static function text(\Dom\Element $element): string
    {
        $copy = $element->cloneNode(true);
        \assert($copy instanceof \Dom\Element);

        foreach ($copy->querySelectorAll('a.nButton') as $button) {
            $button->remove();
        }

        return self::clean($copy->textContent);
    }

    public static function clean(?string $text): string
    {
        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', (string) $text));
    }
}
