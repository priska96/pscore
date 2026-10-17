function pscore_donation_form_shortcode($atts = array()) {
    $labels = shortcode_atts(
        array(
            'one_time_label'           => 'One Time',
            'monthly_label'            => 'Monthly',
            'custom_amount_label'      => 'Other',
            'custom_amount_aria_label' => 'Other donation amount',
            'donate_label'             => 'Donate {amount}',
            'monthly_donate_label'     => 'Donate {amount} Monthly',
            'invalid_amount_message'   => 'Please enter a donation amount of at least {minimum}.',
            'currency_symbol'          => '$',
        ),
        $atts,
        'pscore_donation_form'
    );

    $frequencies = array(
        'one-time' => $labels['one_time_label'],
        'monthly'  => $labels['monthly_label'],
    );
    $amounts = array(10, 25, 50, 100);
    $payment_links = array(
        'one-time' => array(
            10      => 'https://www.paypal.com/ncp/payment/PREV2TRZ33TQ8',
            25      => 'https://www.paypal.com/ncp/payment/VMUNQ6S7LHLPA',
            50      => 'https://www.paypal.com/ncp/payment/P3N9TR2S9EHGC',
            100     => 'https://www.paypal.com/ncp/payment/ERZH94FX2VNUC',
            'other' => 'https://www.paypal.com/ncp/payment/FZQ9CJ4RV974G',
        ),
        'monthly' => array(
            10  => 'https://www.paypal.com/webapps/billing/plans/subscribe?plan_id=P-7HT14067E9418333UNK52NAA',
            25  => 'https://www.paypal.com/webapps/billing/plans/subscribe?plan_id=P-97V26271KP124054ANLBENKY',
            50  => 'https://www.paypal.com/webapps/billing/plans/subscribe?plan_id=P-0RX50063AD152503CNLBEOJI',
            100 => 'https://www.paypal.com/webapps/billing/plans/subscribe?plan_id=P-4H433025H4458125SNLBEO2I',
        ),
    );

    ob_start();
    ?>
    <div
        id="pscore-donation-form"
        class="pscore-donation-form"
        data-donate-label="<?php echo esc_attr($labels['donate_label']); ?>"
        data-monthly-donate-label="<?php echo esc_attr($labels['monthly_donate_label']); ?>"
        data-invalid-amount-message="<?php echo esc_attr($labels['invalid_amount_message']); ?>"
        data-currency-symbol="<?php echo esc_attr($labels['currency_symbol']); ?>"
        data-custom-amount-label="<?php echo esc_attr($labels['custom_amount_label']); ?>"
    >
        <div class="donation-frequency-btn-container">
            <?php foreach ($frequencies as $value => $label) :
                $is_active = 'one-time' === $value;
                ?>
                <div class="elementor-element elementor-element-<?php echo esc_attr($value); ?> elementor-align-center elementor-button-success elementor-widget elementor-widget-button" data-id="<?php echo esc_attr($value); ?>" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
                    <div class="elementor-widget-container">
                        <div class="elementor-button-wrapper">
                            <button class="elementor-button elementor-button-link elementor-size-md donation-frequency-btn<?php echo $is_active ? ' active' : ''; ?>" data-value="<?php echo esc_attr($value); ?>" aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>" type="button">
                                <span class="elementor-button-content-wrapper"><span class="elementor-button-text"><?php echo esc_html($label); ?></span></span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <input type="hidden" id="donation-frequency" name="donation_frequency" value="one-time">
        </div>

        <div class="donation-amount-btn-container">
            <?php foreach ($amounts as $amount) :
                $is_active = 10 === $amount;
                ?>
                <div class="elementor-element elementor-element-<?php echo esc_attr($amount); ?>-dollar elementor-align-center elementor-button-success elementor-widget elementor-widget-button" data-id="<?php echo esc_attr($amount); ?>-dollar" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
                    <div class="elementor-widget-container">
                        <div class="elementor-button-wrapper">
                            <button
                                class="elementor-button elementor-button-link elementor-size-md donation-amount-btn<?php echo $is_active ? ' active' : ''; ?>"
                                data-value="<?php echo esc_attr($amount); ?>"
                                data-one-time-url="<?php echo esc_url($payment_links['one-time'][$amount]); ?>"
                                data-monthly-url="<?php echo esc_url($payment_links['monthly'][$amount]); ?>"
                                aria-pressed="<?php echo $is_active ? 'true' : 'false'; ?>"
                                type="button"
                            >
                                <span class="elementor-button-content-wrapper"><span class="elementor-button-text"><?php echo esc_html($amount . $labels['currency_symbol']); ?></span></span>
                            </button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="elementor-element elementor-element-other-amount elementor-align-center elementor-button-success elementor-widget elementor-widget-button" data-id="other-amount" data-element_type="widget" data-e-type="widget" data-widget_type="button.default">
                <div class="elementor-widget-container">
                    <div class="elementor-button-wrapper">
                        <div class="elementor-button elementor-button-link elementor-size-md donation-amount-btn donation-custom-amount" data-value="other" data-one-time-url="<?php echo esc_url($payment_links['one-time']['other']); ?>" aria-pressed="false" type="button">
                            <input id="custom-donation-amount" type="number" min="1" step="0.01" inputmode="decimal" placeholder="<?php echo esc_attr($labels['custom_amount_label']); ?>" aria-label="<?php echo esc_attr($labels['custom_amount_aria_label']); ?>">
                            <span class="donation-currency"><?php echo esc_html($labels['currency_symbol']); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <input type="hidden" id="donation-amount" name="donation_amount" value="10">
        </div>

        <div class="donation-submit-container">
            <form id="paypal-monthly-form" action="https://www.paypal.com/cgi-bin/webscr" method="post" target="_blank">
                <input type="hidden" name="cmd" value="_xclick-subscriptions">
                <input type="hidden" name="business" value="pscoreadmin@pscore.org">
                <input type="hidden" name="lc" value="US">
                <input type="hidden" name="item_name" value="Monthly Donation to PSCORE">
                <input type="hidden" name="item_number" value="1234">
                <input type="hidden" name="no_note" value="1">
                <input type="hidden" name="no_shipping" value="2">
                <input type="hidden" name="src" value="1">
                <input type="hidden" name="p3" value="1">
                <input type="hidden" name="t3" value="M">
                <input type="hidden" name="currency_code" value="USD">
                <input id="paypal-monthly-amount" type="hidden" name="a3" value="10.00">
            </form>

            <div class="elementor-element elementor-align-center elementor-widget elementor-widget-button">
                <div class="elementor-widget-container">
                    <div class="elementor-button-wrapper">
                        <button id="donation-submit" class="elementor-button elementor-button-link elementor-size-lg donation-submit-btn" type="button">
                            <span id="donation-submit-text" class="elementor-button-content-wrapper">
                                <span class="elementor-button-text"><?php echo esc_html(str_replace('{amount}', $labels['currency_symbol'] . '10', $labels['donate_label'])); ?></span>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php

    return ob_get_clean();
}

add_shortcode('pscore_donation_form', 'pscore_donation_form_shortcode');
