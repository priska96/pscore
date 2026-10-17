<?php
/**
 * Oxilab / Shortcode Addons Content Reader
 *
 * READ ONLY.
 * Does NOT INSERT, UPDATE or DELETE anything.
 */

require_once __DIR__ . '/wp-load.php';

if (!is_user_logged_in() || !current_user_can('manage_options')) {
    status_header(403);
    exit('403 - Administrator login required.');
}

global $wpdb;

header('Content-Type: text/plain; charset=utf-8');

$table = $wpdb->prefix . 'oxi_div_style';

$rows = $wpdb->get_results(
    "SELECT id, name, type, style_name, rawdata, font_family
     FROM `{$table}`
     ORDER BY id ASC",
    ARRAY_A
);

echo "============================================================\n";
echo "OXILAB / SHORTCODE ADDONS - ALL CONTENT\n";
echo "============================================================\n\n";

echo "Table: {$table}\n";
echo "Elements found: " . count($rows) . "\n\n";


foreach ($rows as $row) {

    $id   = $row['id'];
    $name = $row['name'];
    $type = $row['type'];

    echo "============================================================\n";
    echo '[oxi_addons id="' . $id . '"]' . "\n";
    echo "============================================================\n\n";

    echo "ID:    {$id}\n";
    echo "NAME:  {$name}\n";
    echo "TYPE:  {$type}\n";
    echo "STYLE: {$row['style_name']}\n\n";


    /*
     * Parse JSON
     */
    $raw = $row['rawdata'];

    $data = json_decode($raw, true);

    // Old Oxilab data can sometimes be escaped.
    if (!is_array($data)) {
        $data = json_decode(stripslashes($raw), true);
    }


    /*
     * Extract useful information depending on element type
     */
    if (is_array($data)) {

        /*
         * HEADING
         */
        if (strcasecmp($type, 'Heading') === 0) {

            echo "----- HEADING CONTENT -----\n\n";

            echo "HEADING:\n";
            echo clean_value($data['sa_head_text'] ?? '') . "\n\n";

            echo "SUBHEADING:\n";
            echo clean_value($data['sa_sub_head_text'] ?? '') . "\n\n";

            if (!empty($data['sa_head_heading_tag'])) {
                echo "HTML TAG: " . $data['sa_head_heading_tag'] . "\n\n";
            }
        }


        /*
         * VIDEO BOX
         */
        elseif (strcasecmp($type, 'Video_box') === 0) {

            echo "----- VIDEO CONTENT -----\n\n";

            echo "VIDEO URL:\n";
            echo ($data['sa_video_box_video_link'] ?? 'NOT FOUND') . "\n\n";

            if (!empty($data['sa_video_box_image-url'])) {
                echo "THUMBNAIL / IMAGE:\n";
                echo $data['sa_video_box_image-url'] . "\n\n";
            }

            if (!empty($data['sa_video_box_start'])) {
                echo "START: " . $data['sa_video_box_start'] . "\n";
            }

            if (!empty($data['sa_video_box_end'])) {
                echo "END: " . $data['sa_video_box_end'] . "\n";
            }

            echo "\n";
        }


        /*
         * OTHER OXILAB ELEMENT TYPES
         */
        else {

            echo "----- CONTENT / SETTINGS -----\n\n";

            foreach ($data as $key => $value) {

                /*
                 * Skip most layout/style settings to make
                 * the output readable.
                 */
                $style_words = [
                    'margin',
                    'padding',
                    'color',
                    'font',
                    'border',
                    'shadow',
                    'radius',
                    'width',
                    'height',
                    'animation',
                    'responsive',
                ];

                $skip = false;

                foreach ($style_words as $word) {
                    if (stripos($key, $word) !== false) {
                        $skip = true;
                        break;
                    }
                }

                if ($skip) {
                    continue;
                }

                if (
                    is_string($value) &&
                    trim($value) !== ''
                ) {
                    echo $key . ":\n";
                    echo clean_value($value) . "\n\n";
                }
            }
        }

    } else {

        echo "WARNING: rawdata could not be parsed as JSON.\n\n";
        echo "RAW DATA:\n";
        echo $raw . "\n\n";
    }


    echo "\n";
}


/*
 * Helper:
 * Makes stored HTML easier to read in plain text.
 */
function clean_value($value)
{
    if (!is_string($value)) {
        return '';
    }

    $value = str_replace(
        ['<br>', '<br/>', '<br />'],
        "\n",
        $value
    );

    $value = html_entity_decode(
        $value,
        ENT_QUOTES | ENT_HTML5,
        'UTF-8'
    );

    return trim(strip_tags($value));
}


echo "============================================================\n";
echo "READ-ONLY CHECK COMPLETE\n";
echo "============================================================\n\n";

echo "Nothing was changed in the database.\n";