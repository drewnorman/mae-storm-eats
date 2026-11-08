(function ($) {
  "use strict";

  $(function () {
    const root = $("[data-mae-related-recipes]");
    if (!root.length) return;

    const search = root.find("[data-mae-related-search]");
    const results = root.find("[data-mae-related-results]");
    const selected = root.find("[data-mae-related-selected]");
    const input = root.find("[data-mae-related-ids]");
    const empty = root.find("[data-mae-related-empty]");
    const status = root.find("[data-mae-related-status]");
    let matches = [];
    let request;
    let timer;

    function selectedIds() {
      return selected
        .children("[data-id]")
        .map(function () {
          return this.dataset.id;
        })
        .get();
    }

    function update(message) {
      const ids = selectedIds();
      input.val(ids.join(","));
      empty.prop("hidden", ids.length > 0);
      if (message) status.text(message);
    }

    function action(label, icon, attribute) {
      return $("<button>", {
        type: "button",
        class: "button",
        "aria-label": label,
      })
        .attr(attribute, "")
        .append(
          $("<span>", { class: "dashicons " + icon, "aria-hidden": "true" }),
        );
    }

    function itemNode(item) {
      const label = item.title || "Recipe #" + item.id;
      const actions = $("<div>", { class: "mae-related-recipes__actions" });
      actions.append(
        action(
          maeRelatedRecipes.drag,
          "dashicons-move",
          "data-mae-related-drag",
        ).addClass("mae-related-recipes__drag"),
        action(
          maeRelatedRecipes.moveEarlier,
          "dashicons-arrow-up-alt2",
          "data-mae-related-earlier",
        ),
        action(
          maeRelatedRecipes.moveLater,
          "dashicons-arrow-down-alt2",
          "data-mae-related-later",
        ),
        $("<a>", {
          class: "button",
          href: maeRelatedRecipes.editUrl + encodeURIComponent(item.id),
          target: "_blank",
          rel: "noopener noreferrer",
          text: maeRelatedRecipes.edit,
        }),
        $("<button>", {
          type: "button",
          class: "button",
          text: maeRelatedRecipes.remove,
        })
          .attr("data-mae-related-remove", "")
          .append(
            $("<span>", { class: "screen-reader-text", text: " " + label }),
          ),
      );

      return $("<li>", {
        class: "mae-related-recipes__item",
        "data-id": item.id,
      }).append(
        $("<span>", { class: "mae-related-recipes__title", text: label }),
        actions,
      );
    }

    function renderResults() {
      results.empty();
      const chosen = new Set(selectedIds());
      const available = matches.filter((item) => !chosen.has(String(item.id)));
      available.forEach((item) => {
        results.append(
          $("<li>").append(
            $("<button>", {
              type: "button",
              class: "button button-secondary",
              text: maeRelatedRecipes.add + " " + item.title,
            })
              .attr("data-mae-related-add", "")
              .attr("data-id", item.id),
          ),
        );
      });
      results.prop("hidden", available.length === 0);
      if (matches.length && !available.length) {
        status.text(maeRelatedRecipes.noMoreResults);
      } else if (!matches.length && search.val().trim()) {
        status.text(maeRelatedRecipes.noResults);
      } else if (available.length) {
        status.text(
          available.length +
            " " +
            (available.length === 1
              ? maeRelatedRecipes.oneResult
              : maeRelatedRecipes.manyResults),
        );
      }
    }

    function runSearch() {
      const query = String(search.val() || "").trim();
      if (request) request.abort();
      matches = [];
      results.empty().prop("hidden", true);
      if (!query) {
        status.text("");
        return;
      }

      status.text(maeRelatedRecipes.searching);
      request = $.getJSON(maeRelatedRecipes.ajaxUrl, {
        action: "mae_related_recipe_search",
        post_id: maeRelatedRecipes.postId,
        nonce: maeRelatedRecipes.nonce,
        q: query,
      })
        .done((response) => {
          if (!response.success || !Array.isArray(response.data)) {
            status.text(maeRelatedRecipes.searchError);
            return;
          }
          matches = response.data;
          status.text("");
          renderResults();
        })
        .fail((_xhr, state) => {
          if (state !== "abort") status.text(maeRelatedRecipes.searchError);
        });
    }

    search.on("input", function () {
      clearTimeout(timer);
      if (request) request.abort();
      results.empty().prop("hidden", true);
      timer = setTimeout(runSearch, 250);
    });

    results.on("click", "[data-mae-related-add]", function () {
      const id = String(this.dataset.id);
      const item = matches.find((match) => String(match.id) === id);
      if (
        !item ||
        id === String(maeRelatedRecipes.postId) ||
        selectedIds().includes(id)
      )
        return;
      selected.append(itemNode(item));
      renderResults();
      update(maeRelatedRecipes.added);
    });

    selected.on("click", "[data-mae-related-remove]", function () {
      $(this).closest("[data-id]").remove();
      renderResults();
      update(maeRelatedRecipes.removed);
    });

    selected.on("click", "[data-mae-related-earlier]", function () {
      const item = $(this).closest("[data-id]");
      const previous = item.prev("[data-id]");
      if (!previous.length) return;
      item.insertBefore(previous);
      update(maeRelatedRecipes.reordered);
      item.find("[data-mae-related-earlier]").trigger("focus");
    });

    selected.on("click", "[data-mae-related-later]", function () {
      const item = $(this).closest("[data-id]");
      const next = item.next("[data-id]");
      if (!next.length) return;
      item.insertAfter(next);
      update(maeRelatedRecipes.reordered);
      item.find("[data-mae-related-later]").trigger("focus");
    });

    selected.sortable({
      axis: "y",
      handle: "[data-mae-related-drag]",
      update: () => update(maeRelatedRecipes.reordered),
    });
  });
})(jQuery);
