<?php

defined('ABSPATH') || exit;

final class PSCORE_NL_CSS_Inliner {
    public static function inline(string $html, string $css): string {
        if ($html === '' || trim($css) === '' || !class_exists('DOMDocument')) {
            return $html;
        }

        $css = preg_replace('~/\*.*?\*/~s', '', $css);
        $inline_css = preg_replace('/@(?:media|supports|font-face|keyframes)[^{]*\{(?:[^{}]*|\{[^{}]*\})*\}/is', '', $css);
        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $inline_css, $rules, PREG_SET_ORDER);
        if (!$rules) {
            return $html;
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="pscore-nl-inline-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $xpath = new DOMXPath($document);
        foreach ($rules as $rule) {
            $declarations = self::sanitize_declarations($rule[2]);
            if ($declarations === '') {
                continue;
            }
            foreach (explode(',', $rule[1]) as $selector) {
                $query = self::selector_to_xpath(trim($selector));
                if (!$query) {
                    continue;
                }
                $nodes = $xpath->query($query);
                if (!$nodes) {
                    continue;
                }
                foreach ($nodes as $node) {
                    if (!$node instanceof DOMElement || $node->getAttribute('id') === 'pscore-nl-inline-root') {
                        continue;
                    }
                    $existing = trim($node->getAttribute('style'));
                    $style = trim($existing, " \t\n\r\0\x0B;") . ($existing ? ';' : '') . $declarations;
                    if (preg_match('/(?:^|;)\s*flex-direction\s*:\s*row-reverse\s*(?:!important)?\s*(?:;|$)/i', $declarations)) {
                        $style .= 'direction:rtl;';
                        foreach ($node->childNodes as $child) {
                            if ($child instanceof DOMElement) {
                                $child_style = trim($child->getAttribute('style'), " \t\n\r\0\x0B;");
                                $child->setAttribute('style', $child_style . ($child_style ? ';' : '') . 'direction:ltr;');
                            }
                        }
                    }
                    $node->setAttribute('style', $style);
                }
            }
        }

        $root = $xpath->query('//*[@id="pscore-nl-inline-root"]')->item(0);
        if (!$root) {
            return $html;
        }
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }
        return $result;
    }

    private static function sanitize_declarations(string $declarations): string {
        $safe = [];
        foreach (explode(';', $declarations) as $declaration) {
            if (strpos($declaration, ':') === false) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            if (!preg_match('/^--?[a-zA-Z][a-zA-Z0-9_-]*$|^[a-zA-Z][a-zA-Z0-9_-]*$/', $property)) {
                continue;
            }
            if ($value === '' || preg_match('/(?:expression\s*\(|javascript\s*:|behavior\s*:)/i', $value)) {
                continue;
            }
            $safe[] = strtolower($property) . ':' . $value;
        }
        return $safe ? implode(';', $safe) . ';' : '';
    }

    private static function selector_to_xpath(string $selector): string {
        if ($selector === '' || preg_match('/[:\[\]+~]/', $selector)) {
            return '';
        }
        $parts = preg_split('/\s+/', trim($selector));
        $xpath = '';
        foreach ($parts as $part) {
            if ($part === '') continue;
            if (!preg_match('/^([a-zA-Z][a-zA-Z0-9_-]*|\*)?((?:[.#][a-zA-Z_][a-zA-Z0-9_-]*)*)$/', $part, $matches)) {
                return '';
            }
            $tag = empty($matches[1]) || $matches[1] === '*' ? '*' : strtolower($matches[1]);
            $conditions = [];
            if (preg_match_all('/([.#])([a-zA-Z_][a-zA-Z0-9_-]*)/', $matches[2], $tokens, PREG_SET_ORDER)) {
                foreach ($tokens as $token) {
                    if ($token[1] === '#') {
                        $conditions[] = '@id=' . self::xpath_literal($token[2]);
                    } else {
                        $conditions[] = 'contains(concat(" ", normalize-space(@class), " "), ' . self::xpath_literal(' ' . $token[2] . ' ') . ')';
                    }
                }
            }
            $xpath .= '//' . $tag . ($conditions ? '[' . implode(' and ', $conditions) . ']' : '');
        }
        return $xpath;
    }

    private static function xpath_literal(string $value): string {
        return "'" . str_replace("'", '', $value) . "'";
    }
}

