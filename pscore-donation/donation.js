(function () {
  "use strict";

  const MINIMUM_AMOUNT = 1;

  function initDonation(root) {
    if (root.dataset.donationInitialized === "true") return;

    const frequencyButtons = root.querySelectorAll(".donation-frequency-btn");
    const amountButtons = root.querySelectorAll("button.donation-amount-btn");
    const frequencyInput = root.querySelector("#donation-frequency");
    const amountInput = root.querySelector("#donation-amount");
    const customAmount = root.querySelector(".donation-custom-amount");
    const customInput = root.querySelector("#custom-donation-amount");
    const submitButton = root.querySelector(".donation-submit-btn");
    const submitText = submitButton?.querySelector(".elementor-button-text");
    const monthlyForm = root.querySelector("#paypal-monthly-form");
    const monthlyAmount = root.querySelector("#paypal-monthly-amount");

    if (
      !frequencyButtons.length ||
      !amountButtons.length ||
      !frequencyInput ||
      !amountInput ||
      !customAmount ||
      !customInput ||
      !submitButton ||
      !submitText ||
      !monthlyForm ||
      !monthlyAmount
    ) return;

    root.dataset.donationInitialized = "true";
    const labels = {
      donate: root.dataset.donateLabel || "Donate {amount}",
      monthlyDonate:
        root.dataset.monthlyDonateLabel || "Donate {amount} Monthly",
      invalidAmount:
        root.dataset.invalidAmountMessage ||
        "Please enter a donation amount of at least {minimum}.",
      currencySymbol: root.dataset.currencySymbol || "$",
    };
    let frequency = "one-time";
    let amount = 10;
    let selectedAmountButton = amountButtons[0];
    let isCustomAmount = false;

    function setActive(buttons, activeButton) {
      buttons.forEach(function (button) {
        const isActive = button === activeButton;
        button.classList.toggle("active", isActive);
        button.setAttribute("aria-pressed", String(isActive));
      });
    }

    function setCustomActive(isActive) {
      customAmount.classList.toggle("active", isActive);
      customAmount.setAttribute("aria-pressed", String(isActive));
    }

    function formatLabel(template, values) {
      return Object.keys(values).reduce(function (text, key) {
        return text.split(`{${key}}`).join(values[key]);
      }, String(template ?? ""));
    }

    function isValidAmount(value) {
      return Number.isFinite(value) && value >= MINIMUM_AMOUNT;
    }

    function updateDonation() {
      const valid = isValidAmount(amount);
      const paypalAmount = valid ? amount.toFixed(2) : "";
      const displayAmount = valid
        ? `${labels.currencySymbol}${Number.isInteger(amount) ? amount : paypalAmount}`
        : "";
      const template =
        frequency === "monthly"
          ? labels.monthlyDonate
          : labels.donate;

      monthlyAmount.value = paypalAmount;
      amountInput.value = valid ? String(amount) : "";
      submitText.textContent = formatLabel(template, { amount: displayAmount }).trim();
    }

    frequencyButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        frequency = button.dataset.value;
        frequencyInput.value = frequency;
        setActive(frequencyButtons, button);
        updateDonation();
      });
    });

    amountButtons.forEach(function (button) {
      button.addEventListener("click", function () {
        amount = Number(button.dataset.value);
        selectedAmountButton = button;
        isCustomAmount = false;
        customInput.value = "";
        setCustomActive(false);
        setActive(amountButtons, button);
        updateDonation();
      });
    });

    customAmount.addEventListener("click", function () {
      customInput.focus();
    });

    customInput.addEventListener("focus", function () {
      amount = Number(customInput.value);
      selectedAmountButton = null;
      isCustomAmount = true;
      setCustomActive(true);
      setActive(amountButtons, null);
      updateDonation();
    });

    customInput.addEventListener("input", function () {
      amount = Number(customInput.value);
      selectedAmountButton = null;
      isCustomAmount = true;
      setCustomActive(true);
      setActive(amountButtons, null);
      updateDonation();
    });

    submitButton.addEventListener("click", function () {
      if (isCustomAmount && frequency === "monthly") {
        if (!isValidAmount(amount)) {
          customInput.focus();
          alert(
            formatLabel(labels.invalidAmount, {
              minimum: `${labels.currencySymbol}${MINIMUM_AMOUNT}`,
            }),
          );
          return;
        }

        HTMLFormElement.prototype.submit.call(monthlyForm);
        return;
      }

      const paymentUrl = isCustomAmount
        ? customAmount.dataset.oneTimeUrl
        : selectedAmountButton?.dataset[
            frequency === "monthly" ? "monthlyUrl" : "oneTimeUrl"
          ];

      if (!paymentUrl) {
        customInput.focus();
        return;
      }

      window.open(paymentUrl, "_blank", "noopener,noreferrer");
    });

    updateDonation();
  }

  function initAllDonations(scope) {
    if (scope.matches?.(".pscore-donation-form")) initDonation(scope);
    scope.querySelectorAll?.(".pscore-donation-form").forEach(initDonation);
  }

  function start() {
    initAllDonations(document);

    new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        mutation.addedNodes.forEach(function (node) {
          if (node.nodeType === Node.ELEMENT_NODE) initAllDonations(node);
        });
      });
    }).observe(document.body, { childList: true, subtree: true });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", start, { once: true });
  } else {
    start();
  }
})();
