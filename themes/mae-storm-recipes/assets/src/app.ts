import { gsap } from "gsap";

type Unit = {
  label: string;
  value: string;
  factor: number;
};

const units: Record<string, Unit[]> = {
  volume: [
    { label: "tsp", value: "tsp", factor: 4.92892 },
    { label: "tbsp", value: "tbsp", factor: 14.7868 },
    { label: "cup", value: "cup", factor: 236.588 },
    { label: "fl oz", value: "floz", factor: 29.5735 },
    { label: "ml", value: "ml", factor: 1 },
    { label: "l", value: "l", factor: 1000 },
  ],
  weight: [
    { label: "oz", value: "oz", factor: 28.3495 },
    { label: "lb", value: "lb", factor: 453.592 },
    { label: "g", value: "g", factor: 1 },
    { label: "kg", value: "kg", factor: 1000 },
  ],
  temperature: [
    { label: "F", value: "f", factor: 1 },
    { label: "C", value: "c", factor: 1 },
  ],
};

function formatResult(value: number): string {
  if (!Number.isFinite(value)) {
    return "";
  }

  return new Intl.NumberFormat("en-US", {
    maximumFractionDigits: 2,
  }).format(value);
}

function convertTemperature(value: number, from: string, to: string): number {
  if (from === to) {
    return value;
  }

  return from === "f" ? ((value - 32) * 5) / 9 : (value * 9) / 5 + 32;
}

function bootConversions(): void {
  const toggle = document.querySelector<HTMLButtonElement>(
    "[data-conversions-toggle]",
  );
  const panel = document.querySelector<HTMLElement>("[data-conversions-panel]");

  if (!toggle || !panel) {
    return;
  }

  const setOpen = (open: boolean): void => {
    panel.classList.toggle("hidden", !open);
    toggle.setAttribute("aria-expanded", String(open));
  };

  toggle.addEventListener("click", () => {
    setOpen(panel.classList.contains("hidden"));
  });

  panel
    .querySelectorAll<HTMLButtonElement>("[data-conversions-close]")
    .forEach((button) => {
      button.addEventListener("click", () => setOpen(false));
    });

  document.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      setOpen(false);
    }
  });

  document.addEventListener("click", (event) => {
    const target = event.target;

    if (
      target instanceof Node &&
      !panel.contains(target) &&
      !toggle.contains(target)
    ) {
      setOpen(false);
    }
  });
}

function bootConversionCalculator(): void {
  document
    .querySelectorAll<HTMLElement>("[data-conversions-calculator]")
    .forEach((calculator) => {
      const kind = calculator.querySelector<HTMLSelectElement>(
        "[data-conversion-kind]",
      );
      const amount = calculator.querySelector<HTMLInputElement>(
        "[data-conversion-value]",
      );
      const from = calculator.querySelector<HTMLSelectElement>(
        "[data-conversion-from]",
      );
      const to = calculator.querySelector<HTMLSelectElement>(
        "[data-conversion-to]",
      );
      const result = calculator.querySelector<HTMLOutputElement>(
        "[data-conversion-result]",
      );

      if (!kind || !amount || !from || !to || !result) {
        return;
      }

      const populateUnits = (): void => {
        const selectedUnits = units[kind.value] ?? units.volume;
        const options = selectedUnits
          .map((unit) => `<option value="${unit.value}">${unit.label}</option>`)
          .join("");

        from.innerHTML = options;
        to.innerHTML = options;
        to.selectedIndex = Math.min(1, selectedUnits.length - 1);
      };

      const calculate = (): void => {
        const value = Number.parseFloat(amount.value);
        const selectedUnits = units[kind.value] ?? units.volume;
        const fromUnit = selectedUnits.find(
          (unit) => unit.value === from.value,
        );
        const toUnit = selectedUnits.find((unit) => unit.value === to.value);

        if (!fromUnit || !toUnit) {
          result.value = "";
          return;
        }

        const converted =
          kind.value === "temperature"
            ? convertTemperature(value, fromUnit.value, toUnit.value)
            : (value * fromUnit.factor) / toUnit.factor;

        result.value = formatResult(converted);
      };

      kind.addEventListener("change", () => {
        populateUnits();
        calculate();
      });
      amount.addEventListener("input", calculate);
      from.addEventListener("change", calculate);
      to.addEventListener("change", calculate);

      populateUnits();
      calculate();
    });
}

function bootFilterSelects(): void {
  document
    .querySelectorAll<HTMLSelectElement>("[data-filter-select]")
    .forEach((select) => {
      select.addEventListener("change", () => {
        if (select.value) {
          window.location.assign(select.value);
        }
      });
    });
}

function bootMobileSearch(): void {
  const toggle = document.querySelector<HTMLButtonElement>(
    "[data-mobile-search-toggle]",
  );
  const panel = document.querySelector<HTMLElement>(
    "[data-mobile-search-panel]",
  );

  if (!toggle || !panel) {
    return;
  }

  toggle.addEventListener("click", () => {
    const open = toggle.getAttribute("aria-expanded") !== "true";
    toggle.setAttribute("aria-expanded", String(open));
    panel.classList.toggle("max-sm:hidden", !open);

    if (open) {
      panel.querySelector<HTMLInputElement>('input[type="search"]')?.focus();
    } else {
      toggle.focus();
    }
  });
}

function bootRecipeShell(): void {
  const shell = document.querySelector<HTMLElement>("[data-recipe-shell]");

  if (shell) {
    gsap.from(shell, {
      autoAlpha: 0,
      duration: 0.28,
      ease: "power2.out",
      y: 8,
    });
  }

  document
    .querySelectorAll<HTMLElement>("[data-print-recipe]")
    .forEach((button) => {
      button.addEventListener("click", () => window.print());
    });

  bootConversions();
  bootConversionCalculator();
  bootFilterSelects();
  bootMobileSearch();
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", bootRecipeShell, {
    once: true,
  });
} else {
  bootRecipeShell();
}
