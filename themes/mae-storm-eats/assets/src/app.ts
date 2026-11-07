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
  const filterMenu = document.querySelector<HTMLDetailsElement>(
    "[data-recipe-filter-menu]",
  );
  const filterTrigger = filterMenu?.querySelector<HTMLElement>("summary");
  filterMenu?.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      filterMenu.open = false;
      filterTrigger?.focus();
    }
  });
  document.addEventListener("click", (event) => {
    if (filterMenu?.open && !filterMenu.contains(event.target as Node)) {
      filterMenu.open = false;
    }
  });
  document
    .querySelectorAll<HTMLFormElement>("[data-recipe-filters]")
    .forEach((form) => {
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        const destination = new URL(form.action);
        for (const [key, value] of new FormData(form).entries()) {
          const trimmed = String(value).trim();
          if (trimmed) {
            destination.searchParams.append(key, trimmed);
          }
        }
        window.location.assign(destination.toString());
      });
    });
  document
    .querySelectorAll<HTMLSelectElement>(
      "[data-recipe-filters] select[name]:not([multiple])",
    )
    .forEach((select) => {
      select.addEventListener("change", () => select.form?.requestSubmit());
    });
  document
    .querySelectorAll<HTMLInputElement>(
      "[data-recipe-filters] input[type='radio']",
    )
    .forEach((radio) => {
      radio.addEventListener("change", () => radio.form?.requestSubmit());
    });

  document
    .querySelectorAll<HTMLElement>("[data-ingredient-picker]")
    .forEach((picker) => {
      const select = picker.querySelector<HTMLSelectElement>(
        "[data-ingredient-select]",
      );
      const enhancement = picker.querySelector<HTMLElement>(
        "[data-ingredient-enhancement]",
      );
      const search = picker.querySelector<HTMLInputElement>(
        "[data-ingredient-search]",
      );
      const addButton = picker.querySelector<HTMLButtonElement>(
        "[data-ingredient-add]",
      );
      const chips = picker.querySelector<HTMLElement>(
        "[data-ingredient-chips]",
      );
      const feedback = picker.querySelector<HTMLElement>(
        "[data-ingredient-feedback]",
      );

      if (
        !select ||
        !enhancement ||
        !search ||
        !addButton ||
        !chips ||
        !feedback
      ) {
        return;
      }

      const options = Array.from(select.options);
      const renderChips = (): void => {
        chips.replaceChildren();
        options
          .filter((option) => option.selected)
          .forEach((option) => {
            const button = document.createElement("button");
            button.type = "button";
            button.textContent = `${option.text} ×`;
            button.setAttribute("aria-label", `Remove ${option.text}`);
            button.addEventListener("click", () => {
              option.selected = false;
              renderChips();
              feedback.textContent = `${option.text} removed`;
              select.form?.requestSubmit();
            });
            chips.append(button);
          });
      };

      const addIngredient = (): void => {
        const name = search.value.trim().toLocaleLowerCase();
        if (!name) {
          return;
        }

        const option = options.find(
          (candidate) => candidate.text.trim().toLocaleLowerCase() === name,
        );
        if (!option) {
          feedback.textContent = "Choose an ingredient from the suggestions.";
          return;
        }

        option.selected = true;
        search.value = "";
        renderChips();
        feedback.textContent = `${option.text} selected`;
        select.form?.requestSubmit();
      };

      select.hidden = true;
      picker
        .querySelector<HTMLLabelElement>('label[for="ingredient-filter"]')
        ?.setAttribute("for", "ingredient-search");
      enhancement.hidden = false;
      picker
        .closest<HTMLFormElement>("form")
        ?.querySelector<HTMLButtonElement>(".recipe-more__apply")
        ?.setAttribute("hidden", "");
      renderChips();
      addButton.addEventListener("click", addIngredient);
      search.addEventListener("change", () => {
        if (
          options.some(
            (option) =>
              option.text.trim().toLocaleLowerCase() ===
              search.value.trim().toLocaleLowerCase(),
          )
        ) {
          addIngredient();
        }
      });
      search.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
          event.preventDefault();
          addIngredient();
        }
      });
    });
}

function bootRecipeListing(): void {
  const results = document.querySelector<HTMLElement>("[data-recipe-results]");
  if (!results) return;

  const viewInput = document.querySelector<HTMLInputElement>(
    "[data-recipe-view-input]",
  );
  const viewLinks = Array.from(
    document.querySelectorAll<HTMLAnchorElement>("[data-recipe-view-link]"),
  );
  const viewMenu = document.querySelector<HTMLDetailsElement>(
    "[data-recipe-view-menu]",
  );
  const viewTrigger = viewMenu?.querySelector<HTMLElement>("summary");
  const viewTriggerIcon = viewMenu?.querySelector<HTMLElement>(
    "[data-recipe-view-trigger-icon]",
  );
  let currentView = results.dataset.recipeView ?? "card";

  const nextLink = document.querySelector<HTMLAnchorElement>(
    "[data-recipe-next-page]",
  );
  const setNextLinkView = (): void => {
    if (!nextLink) return;
    const url = new URL(nextLink.href);
    url.searchParams.set("view", currentView);
    nextLink.href = url.toString();
  };

  viewLinks.forEach((link) => {
    link.addEventListener("click", (event) => {
      if (
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
      ) {
        return;
      }

      const view = link.dataset.recipeViewLink;
      if (view !== "card" && view !== "grid" && view !== "list") return;
      event.preventDefault();
      currentView = view;
      results.dataset.recipeView = view;
      if (viewInput) viewInput.value = view;
      viewLinks.forEach((item) => {
        const selected = item.dataset.recipeViewLink === view;
        item.hidden = selected;
        if (selected) {
          item.setAttribute("aria-current", "true");
        } else {
          item.removeAttribute("aria-current");
        }
      });
      const icon = link.querySelector("svg");
      if (icon && viewTriggerIcon) {
        viewTriggerIcon.replaceChildren(icon.cloneNode(true));
      }
      viewTrigger?.setAttribute(
        "aria-label",
        `Current view: ${link.textContent?.trim() ?? view}. Change recipe view`,
      );
      if (viewMenu) viewMenu.open = false;
      viewTrigger?.focus();
      const url = new URL(window.location.href);
      url.searchParams.set("view", view);
      window.history.replaceState(window.history.state, "", url);
      setNextLinkView();
    });
  });

  viewMenu?.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      viewMenu.open = false;
      viewTrigger?.focus();
    }
  });
  document.addEventListener("click", (event) => {
    if (viewMenu?.open && !viewMenu.contains(event.target as Node)) {
      viewMenu.open = false;
    }
  });

  const pagination = document.querySelector<HTMLElement>(
    "[data-recipe-pagination]",
  );
  const status = document.querySelector<HTMLElement>(
    "[data-recipe-loading-status]",
  );
  const retry = document.querySelector<HTMLButtonElement>(
    "[data-recipe-loading-retry]",
  );
  if (!pagination || !nextLink || !status || !retry) return;

  let loading = false;
  let failed = false;
  let finished = false;

  const loadNext = async (): Promise<void> => {
    if (loading || failed || finished) return;
    loading = true;
    status.textContent = "Loading more recipes…";
    const requestUrl = nextLink.href;

    try {
      const response = await fetch(requestUrl, { credentials: "same-origin" });
      if (!response.ok) throw new Error("Recipe page request failed");
      const page = new DOMParser().parseFromString(
        await response.text(),
        "text/html",
      );
      const newResults = page.querySelector<HTMLElement>(
        "[data-recipe-results]",
      );
      if (!newResults) throw new Error("Recipe results were missing");
      const cards = Array.from(newResults.querySelectorAll(".recipe-card"));
      if (cards.length === 0) throw new Error("Recipe page was empty");
      cards.forEach((card) => results.append(document.importNode(card, true)));

      const following = page.querySelector<HTMLAnchorElement>(
        "[data-recipe-next-page]",
      );
      if (following) {
        nextLink.href = new URL(following.href, requestUrl).toString();
        setNextLinkView();
        status.textContent = `${cards.length} more recipes loaded.`;
      } else {
        finished = true;
        pagination.hidden = true;
        status.textContent = `${cards.length} more recipes loaded. All recipes are shown.`;
      }
      retry.hidden = true;
    } catch {
      failed = true;
      status.textContent = "More recipes could not be loaded.";
      retry.hidden = false;
    } finally {
      loading = false;
    }
  };

  nextLink.addEventListener("click", (event) => {
    if (
      event.button !== 0 ||
      event.metaKey ||
      event.ctrlKey ||
      event.shiftKey ||
      event.altKey
    ) {
      return;
    }
    event.preventDefault();
    failed = false;
    void loadNext();
  });
  retry.addEventListener("click", () => {
    failed = false;
    retry.hidden = true;
    void loadNext();
  });

  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) void loadNext();
      },
      { rootMargin: "600px 0px" },
    );
    observer.observe(pagination);
  }
}

function bootRecipeGalleries(): void {
  document
    .querySelectorAll<HTMLElement>("[data-recipe-gallery]")
    .forEach((gallery) => {
      const slides = Array.from(
        gallery.querySelectorAll<HTMLElement>("[data-recipe-gallery-slide]"),
      );
      if (slides.length < 2) return;

      const previous = gallery.querySelector<HTMLButtonElement>(
        "[data-recipe-gallery-previous]",
      );
      const next = gallery.querySelector<HTMLButtonElement>(
        "[data-recipe-gallery-next]",
      );
      const position = gallery.querySelector<HTMLElement>(
        "[data-recipe-gallery-position]",
      );
      let index = 0;

      const show = (nextIndex: number): void => {
        slides[index]
          .querySelectorAll("video")
          .forEach((video) => video.pause());
        slides[index].hidden = true;
        index = (nextIndex + slides.length) % slides.length;
        slides[index].hidden = false;
        if (position) position.textContent = `${index + 1} / ${slides.length}`;
      };

      previous?.addEventListener("click", () => show(index - 1));
      next?.addEventListener("click", () => show(index + 1));
      gallery.addEventListener("keydown", (event) => {
        if (event.target instanceof HTMLVideoElement) return;
        if (event.key === "ArrowLeft" || event.key === "ArrowRight") {
          event.preventDefault();
          show(index + (event.key === "ArrowRight" ? 1 : -1));
        }
      });
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
  bootRecipeListing();
  bootRecipeGalleries();
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", bootRecipeShell, {
    once: true,
  });
} else {
  bootRecipeShell();
}
