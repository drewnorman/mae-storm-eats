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
  const sortMenu = document.querySelector<HTMLDetailsElement>(
    "[data-recipe-sort-menu]",
  );
  const sortTrigger = sortMenu?.querySelector<HTMLElement>("summary");
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
    if (sortMenu?.open && !sortMenu.contains(event.target as Node)) {
      sortMenu.open = false;
    }
  });
  sortMenu?.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      sortMenu.open = false;
      sortTrigger?.focus();
    }
  });
  sortMenu?.addEventListener("change", () => {
    sortMenu.open = false;
    sortTrigger?.focus();
  });
  document
    .querySelectorAll<HTMLFormElement>("[data-recipe-filters]")
    .forEach((form) => {
      form
        .querySelector<HTMLButtonElement>("[data-recipe-sort-apply]")
        ?.setAttribute("hidden", "");
      form.addEventListener("submit", (event) => {
        event.preventDefault();
        const destination = new URL(form.action);
        for (const [key, value] of new FormData(form).entries()) {
          const trimmed = String(value).trim();
          if (trimmed) {
            destination.searchParams.append(key, trimmed);
          }
        }
        if (!destination.searchParams.has("ingredient[]")) {
          destination.searchParams.delete("ingredient_mode");
        }
        const request = new CustomEvent("recipe:request", {
          bubbles: true,
          cancelable: true,
          detail: { url: destination },
        });
        if (form.dispatchEvent(request)) {
          window.location.assign(destination.toString());
        }
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
      picker.addEventListener("recipe:filters-sync", renderChips);
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
  const form = document.querySelector<HTMLFormElement>("[data-recipe-filters]");
  const endpoint = form?.dataset.recipeAjaxEndpoint;

  const nextLink = document.querySelector<HTMLAnchorElement>(
    "[data-recipe-next-page]",
  );
  const setNextLinkView = (): void => {
    if (!nextLink) return;
    const url = new URL(nextLink.href);
    url.searchParams.set("view", currentView);
    nextLink.href = url.toString();
  };

  const setView = (
    view: string,
    historyAction: "push" | "replace" | "none",
  ): void => {
    if (view !== "card" && view !== "grid" && view !== "list") return;
    const changed = currentView !== view;
    currentView = view;
    results.dataset.recipeView = view;
    if (viewInput) viewInput.value = view;
    const currentLink = viewLinks.find(
      (item) => item.dataset.recipeViewLink === view,
    );
    viewLinks.forEach((item) => {
      const selected = item === currentLink;
      if (selected) {
        item.setAttribute("aria-current", "true");
      } else {
        item.removeAttribute("aria-current");
      }
    });
    const icon = currentLink?.querySelector("svg");
    if (icon && viewTriggerIcon) {
      viewTriggerIcon.replaceChildren(icon.cloneNode(true));
    }
    viewTrigger?.setAttribute(
      "aria-label",
      `Current view: ${{ card: "Card", grid: "Grid", list: "List" }[view]}. Change recipe view`,
    );
    if (viewMenu) viewMenu.open = false;
    if (historyAction !== "none" && changed) {
      const url = new URL(window.location.href);
      url.searchParams.set("view", view);
      window.history[historyAction === "push" ? "pushState" : "replaceState"](
        window.history.state,
        "",
        url,
      );
    }
    setNextLinkView();
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
      setView(view, endpoint ? "push" : "replace");
      if (endpoint) form?.dispatchEvent(new Event("recipe:view-changed"));
      viewTrigger?.focus();
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

  if (endpoint && form) {
    bootAjaxRecipeListing({
      endpoint,
      form,
      results,
      pagination,
      nextLink,
      status,
      retry,
      setView,
      viewLinks,
    });
    return;
  }

  let loading = false;
  let failed = false;
  let finished = false;

  const loadNext = async (): Promise<void> => {
    if (loading || failed || finished || pagination.hidden) return;
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

type RecipeListingPayload = {
  html: string;
  total: number;
  page: number;
  pages: number;
};

function bootAjaxRecipeListing(parts: {
  endpoint: string;
  form: HTMLFormElement;
  results: HTMLElement;
  pagination: HTMLElement;
  nextLink: HTMLAnchorElement;
  status: HTMLElement;
  retry: HTMLButtonElement;
  setView: (view: string, historyAction: "push" | "replace" | "none") => void;
  viewLinks: HTMLAnchorElement[];
}): void {
  const {
    endpoint,
    form,
    results,
    pagination,
    nextLink,
    status,
    retry,
    setView,
    viewLinks,
  } = parts;
  const count = document.querySelector<HTMLElement>(
    "[data-recipe-results-count]",
  );
  const chips = document.querySelector<HTMLElement>(
    "[data-recipe-filter-chips]",
  );
  const filterSummary = document.querySelector<HTMLElement>(
    "[data-recipe-filter-menu] summary",
  );
  const search = form.querySelector<HTMLInputElement>('input[name="s"]');
  const searchSummary = document.querySelector<HTMLElement>(
    "[data-recipe-search-summary]",
  );
  const titleSuffix = document.title.split(" – ").slice(-1)[0];
  const ingredientSelect = form.querySelector<HTMLSelectElement>(
    '[name="ingredient[]"]',
  );
  let currentUrl = new URL(window.location.href);
  let currentPage = Number(results.dataset.recipePage ?? 1);
  let pages = Number(results.dataset.recipePages ?? 1);
  let requestId = 0;
  let controller: AbortController | null = null;
  let loadingMore = false;
  let retryAction: (() => void) | null = null;
  let searchTimer: number | undefined;

  const queryKey = (url: URL): string => {
    const copy = new URL(url);
    copy.searchParams.delete("view");
    copy.searchParams.sort();
    return `${copy.pathname}?${copy.searchParams.toString()}`;
  };
  const pageFromUrl = (url: URL): number => {
    const queryPage = Number(
      url.searchParams.get("paged") ?? url.searchParams.get("page"),
    );
    if (Number.isInteger(queryPage) && queryPage > 0) return queryPage;
    const pathPage = Number(url.pathname.match(/\/page\/(\d+)\/?$/)?.[1]);
    return Number.isInteger(pathPage) && pathPage > 0 ? pathPage : 1;
  };
  let currentQueryKey = queryKey(currentUrl);
  let renderedQueryKey = currentQueryKey;

  const apiUrl = (url: URL, page: number): URL => {
    const request = new URL(endpoint);
    for (const key of [
      "s",
      "course",
      "cuisine",
      "occasion",
      "vibe",
      "ingredient[]",
      "ingredient",
      "ingredient_mode",
      "sort",
    ]) {
      for (const value of url.searchParams.getAll(key)) {
        if (value) request.searchParams.append(key, value);
      }
    }
    request.searchParams.set("page", String(page));
    return request;
  };

  const updateLinks = (): void => {
    for (const link of viewLinks) {
      const url = new URL(currentUrl);
      url.searchParams.set("view", link.dataset.recipeViewLink ?? "card");
      link.href = url.toString();
    }
    const next = new URL(form.action);
    for (const [key, value] of currentUrl.searchParams) {
      if (key !== "paged" && key !== "page") {
        next.searchParams.append(key, value);
      }
    }
    next.searchParams.set("paged", String(currentPage + 1));
    nextLink.href = next.toString();
    pagination.hidden = currentPage >= pages;
  };

  const syncForm = (): void => {
    if (search) search.value = currentUrl.searchParams.get("s") ?? "";
    for (const select of form.querySelectorAll<HTMLSelectElement>(
      "select[name]:not([multiple])",
    )) {
      select.value = currentUrl.searchParams.get(select.name) ?? "";
    }
    const sortValue = currentUrl.searchParams.get("sort") ?? "recent";
    const sortRadio = Array.from(
      form.querySelectorAll<HTMLInputElement>('input[name="sort"]'),
    ).find((input) => input.value === sortValue);
    if (sortRadio) sortRadio.checked = true;
    const sortSummary = form.querySelector<HTMLElement>(
      "[data-recipe-sort-menu] summary",
    );
    const sortLabel = sortRadio?.closest("label")?.textContent?.trim();
    sortSummary?.setAttribute(
      "aria-label",
      `Sort by ${sortLabel ?? "Recently added"}. Change recipe sort`,
    );
    const ingredients = new Set([
      ...currentUrl.searchParams.getAll("ingredient[]"),
      ...currentUrl.searchParams.getAll("ingredient"),
    ]);
    if (ingredientSelect) {
      for (const option of ingredientSelect.options) {
        option.selected = ingredients.has(option.value);
      }
      ingredientSelect
        .closest("[data-ingredient-picker]")
        ?.dispatchEvent(new Event("recipe:filters-sync"));
    }
    const mode = currentUrl.searchParams.get("ingredient_mode") ?? "any";
    const radio = form.querySelector<HTMLInputElement>(
      `input[name="ingredient_mode"][value="${mode === "all" ? "all" : "any"}"]`,
    );
    if (radio) radio.checked = true;
    setView(currentUrl.searchParams.get("view") ?? "card", "none");
  };

  const renderChips = (): void => {
    if (!chips) return;
    chips.replaceChildren();
    let active = 0;
    const addChip = (label: string, url: URL): void => {
      const chip = document.createElement("a");
      chip.className = "recipe-filter-chip";
      chip.href = url.toString();
      chip.textContent = `${label} ×`;
      chip.setAttribute("aria-label", `Remove ${label} filter`);
      chip.dataset.recipeFilterChip = "";
      chips.append(chip);
      active += 1;
    };
    for (const [key, label] of [
      ["course", "Course"],
      ["cuisine", "Cuisine"],
      ["occasion", "Occasion"],
      ["vibe", "Vibe"],
    ]) {
      const value = currentUrl.searchParams.get(key);
      if (!value) continue;
      const select = form.querySelector<HTMLSelectElement>(
        `select[name="${key}"]`,
      );
      const name = Array.from(select?.options ?? []).find(
        (option) => option.value === value,
      )?.textContent;
      const next = new URL(currentUrl);
      next.searchParams.delete(key);
      addChip(`${label}: ${name ?? value}`, next);
    }
    const ingredients = [
      ...currentUrl.searchParams.getAll("ingredient[]"),
      ...currentUrl.searchParams.getAll("ingredient"),
    ];
    for (const value of ingredients) {
      const name = Array.from(ingredientSelect?.options ?? []).find(
        (option) => option.value === value,
      )?.textContent;
      const next = new URL(currentUrl);
      const remaining = ingredients.filter(
        (ingredient) => ingredient !== value,
      );
      next.searchParams.delete("ingredient[]");
      next.searchParams.delete("ingredient");
      remaining.forEach((ingredient) =>
        next.searchParams.append("ingredient[]", ingredient),
      );
      if (remaining.length === 0) next.searchParams.delete("ingredient_mode");
      addChip(`Ingredient: ${name ?? value}`, next);
    }
    const clear = new URL(form.action);
    for (const key of ["s", "sort", "view"]) {
      const value = currentUrl.searchParams.get(key);
      if (value) clear.searchParams.set(key, value);
    }
    const clearLink = document.createElement("a");
    clearLink.className = "recipe-filters__clear";
    clearLink.href = clear.toString();
    clearLink.textContent = "Clear filters";
    clearLink.dataset.recipeClearFilters = "";
    chips.append(clearLink);
    chips.hidden = active === 0;
    if (filterSummary) {
      filterSummary.setAttribute(
        "aria-label",
        active
          ? `Filters, ${active} active. Change recipe filters`
          : "Filters. Change recipe filters",
      );
    }
  };

  const displayResults = (
    payload: RecipeListingPayload,
    append: boolean,
  ): number => {
    const template = document.createElement("template");
    template.innerHTML = payload.html;
    const cards = Array.from(template.content.querySelectorAll(".recipe-card"));
    if (append) {
      cards.forEach((card) => results.append(card));
    } else if (cards.length > 0) {
      results.replaceChildren(...cards);
    } else {
      const empty = document.createElement("div");
      empty.className = "recipe-results-empty";
      empty.textContent = [
        "s",
        "course",
        "cuisine",
        "occasion",
        "vibe",
        "ingredient[]",
        "ingredient",
      ].some((key) => currentUrl.searchParams.has(key))
        ? "No recipes match your search or filters."
        : "No recipes are ready yet.";
      results.replaceChildren(empty);
    }
    currentPage = payload.page;
    pages = payload.pages;
    renderedQueryKey = currentQueryKey;
    results.dataset.recipePage = String(currentPage);
    results.dataset.recipePages = String(pages);
    if (count) {
      count.textContent = `${payload.total} ${payload.total === 1 ? "recipe" : "recipes"}`;
    }
    updateLinks();
    return cards.length;
  };

  const fetchPage = async (page: number, append: boolean): Promise<void> => {
    requestId += 1;
    const id = requestId;
    controller?.abort();
    controller = new AbortController();
    const signal = controller.signal;
    retry.hidden = true;
    retryAction = null;
    results.setAttribute("aria-busy", "true");
    status.textContent = append ? "Loading more recipes…" : "Updating recipes…";
    if (!append) pagination.hidden = true;
    if (append) loadingMore = true;
    try {
      const response = await fetch(apiUrl(currentUrl, page), {
        credentials: "same-origin",
        signal,
      });
      if (id !== requestId) return;
      if (response.status === 401) {
        window.location.assign(currentUrl.toString());
        return;
      }
      if (!response.ok) throw new Error("Recipe listing request failed");
      const payload = (await response.json()) as RecipeListingPayload;
      if (
        typeof payload.html !== "string" ||
        !Number.isInteger(payload.total) ||
        !Number.isInteger(payload.page) ||
        !Number.isInteger(payload.pages)
      ) {
        throw new Error("Invalid recipe listing response");
      }
      if (id !== requestId) return;
      const loaded = displayResults(payload, append);
      status.textContent = append
        ? `${loaded} more recipes loaded.${currentPage >= pages ? " All recipes are shown." : ""}`
        : `${payload.total} ${payload.total === 1 ? "recipe" : "recipes"} found.`;
    } catch (error) {
      if (signal.aborted || id !== requestId) return;
      status.textContent = append
        ? "More recipes could not be loaded."
        : "Recipes could not be updated.";
      retry.textContent = append
        ? "Retry loading recipes"
        : "Retry updating recipes";
      retry.hidden = false;
      retryAction = () => void fetchPage(page, append);
    } finally {
      if (id === requestId) {
        controller = null;
        loadingMore = false;
        results.removeAttribute("aria-busy");
      }
    }
  };

  const applyUrl = (url: URL, push: boolean): void => {
    if (push && url.toString() !== currentUrl.toString()) {
      window.history.pushState(window.history.state, "", url);
    }
    currentUrl = url;
    currentQueryKey = queryKey(url);
    syncForm();
    const searchTerm = currentUrl.searchParams.get("s")?.trim() ?? "";
    if (searchSummary) {
      searchSummary.hidden = searchTerm === "";
      searchSummary.textContent = searchTerm
        ? `Results for “${searchTerm}”`
        : "";
    }
    document.title = searchTerm
      ? `Search Results for “${searchTerm}” – ${titleSuffix}`
      : `Recipes – ${titleSuffix}`;
    renderChips();
    updateLinks();
    if (currentQueryKey !== renderedQueryKey) {
      void fetchPage(pageFromUrl(url), false);
    } else {
      if (controller) {
        requestId += 1;
        controller.abort();
        controller = null;
        loadingMore = false;
        results.removeAttribute("aria-busy");
      }
      retry.hidden = true;
      retryAction = null;
      status.textContent = "";
    }
  };

  form.addEventListener("recipe:request", (event) => {
    const request = event as CustomEvent<{ url: URL }>;
    if (!(request.detail?.url instanceof URL)) return;
    event.preventDefault();
    window.clearTimeout(searchTimer);
    applyUrl(request.detail.url, true);
  });
  form.addEventListener("recipe:view-changed", () => {
    currentUrl = new URL(window.location.href);
    renderChips();
    updateLinks();
  });
  chips?.addEventListener("click", (event) => {
    if (
      !(event.target instanceof Element) ||
      (event as MouseEvent).button !== 0 ||
      (event as MouseEvent).metaKey ||
      (event as MouseEvent).ctrlKey ||
      (event as MouseEvent).shiftKey ||
      (event as MouseEvent).altKey
    ) {
      return;
    }
    const link = event.target.closest<HTMLAnchorElement>("a[href]");
    if (!link) return;
    event.preventDefault();
    applyUrl(new URL(link.href), true);
  });
  const scheduleSearch = (): void => {
    window.clearTimeout(searchTimer);
    if (controller) {
      requestId += 1;
      controller.abort();
      controller = null;
      loadingMore = false;
      results.removeAttribute("aria-busy");
    }
    pagination.hidden = true;
    status.textContent = "Searching recipes…";
    searchTimer = window.setTimeout(() => form.requestSubmit(), 300);
  };
  let composing = false;
  search?.addEventListener("compositionstart", () => {
    composing = true;
  });
  search?.addEventListener("compositionend", () => {
    composing = false;
    scheduleSearch();
  });
  search?.addEventListener("input", () => {
    if (!composing) scheduleSearch();
  });
  window.addEventListener("popstate", () => {
    window.clearTimeout(searchTimer);
    applyUrl(new URL(window.location.href), false);
  });
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
    if (controller || loadingMore || currentPage >= pages) return;
    void fetchPage(currentPage + 1, true);
  });
  retry.addEventListener("click", () => retryAction?.());
  if ("IntersectionObserver" in window) {
    const observer = new IntersectionObserver(
      (entries) => {
        if (
          entries.some((entry) => entry.isIntersecting) &&
          !pagination.hidden &&
          !controller &&
          !loadingMore
        ) {
          void fetchPage(currentPage + 1, true);
        }
      },
      { rootMargin: "600px 0px" },
    );
    observer.observe(pagination);
  }
  renderChips();
  updateLinks();
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
