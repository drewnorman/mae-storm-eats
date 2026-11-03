(function ($) {
  "use strict";

  $(function () {
    const root = $("[data-mae-recipe-gallery]");
    if (!root.length) return;

    const list = root.find("[data-mae-recipe-gallery-items]");
    const input = root.find("[data-mae-recipe-gallery-ids]");
    const empty = root.find("[data-mae-recipe-gallery-empty]");
    const status = root.find("[data-mae-recipe-gallery-status]");
    const featuredId = String(root.data("featured-id") || "");
    const videoMimes = new Set(["video/mp4", "video/webm", "video/ogg"]);
    let frame;

    function update(message) {
      input.val(
        list
          .children("[data-id]")
          .map(function () {
            return this.dataset.id;
          })
          .get()
          .join(","),
      );
      empty.prop("hidden", list.children("[data-id]").length > 0);
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
      const label = item.title || item.filename || "Media #" + item.id;
      const size = item.sizes && (item.sizes.thumbnail || item.sizes.medium);
      const previewUrl = item.type === "image" && size ? size.url : item.icon;
      const node = $("<li>", {
        class: "mae-recipe-gallery__item",
        "data-id": item.id,
      });
      const preview = $("<div>", { class: "mae-recipe-gallery__preview" });
      if (previewUrl) preview.append($("<img>", { src: previewUrl, alt: "" }));

      const actions = $("<div>", { class: "mae-recipe-gallery__actions" });
      actions.append(
        action(
          maeRecipeGallery.drag,
          "dashicons-move",
          "data-mae-recipe-gallery-drag",
        ).addClass("mae-recipe-gallery__drag"),
      );
      actions.append(
        action(
          maeRecipeGallery.moveEarlier,
          "dashicons-arrow-left-alt2",
          "data-mae-recipe-gallery-earlier",
        ),
      );
      actions.append(
        action(
          maeRecipeGallery.moveLater,
          "dashicons-arrow-right-alt2",
          "data-mae-recipe-gallery-later",
        ),
      );
      actions.append(
        $("<a>", {
          class: "button",
          href: maeRecipeGallery.editUrl + encodeURIComponent(item.id),
          target: "_blank",
          rel: "noopener noreferrer",
          text: maeRecipeGallery.edit,
        }).append(
          $("<span>", { class: "screen-reader-text", text: " " + label }),
        ),
      );
      actions.append(
        $("<button>", {
          type: "button",
          class: "button",
          text: maeRecipeGallery.remove,
        })
          .attr("data-mae-recipe-gallery-remove", "")
          .append(
            $("<span>", { class: "screen-reader-text", text: " " + label }),
          ),
      );
      return node.append(
        preview,
        $("<span>", { class: "mae-recipe-gallery__label", text: label }),
        actions,
      );
    }

    root.find("[data-mae-recipe-gallery-add]").on("click", function () {
      if (!frame) {
        frame = wp.media({
          title: maeRecipeGallery.mediaTitle,
          button: { text: maeRecipeGallery.mediaButton },
          library: { type: ["image", "video"] },
          multiple: "add",
        });
        frame.on("select", function () {
          const existing = new Set(
            list
              .children("[data-id]")
              .map(function () {
                return this.dataset.id;
              })
              .get(),
          );
          existing.add(featuredId);
          frame
            .state()
            .get("selection")
            .each(function (attachment) {
              const item = attachment.toJSON();
              if (
                (item.type === "image" ||
                  (item.type === "video" && videoMimes.has(item.mime))) &&
                !existing.has(String(item.id))
              ) {
                list.append(itemNode(item));
                existing.add(String(item.id));
              }
            });
          update(maeRecipeGallery.added);
        });
      }
      frame.open();
      frame.state().get("selection").reset();
    });

    list.on("click", "[data-mae-recipe-gallery-remove]", function () {
      $(this).closest("[data-id]").remove();
      update(maeRecipeGallery.removed);
    });
    list.on("click", "[data-mae-recipe-gallery-earlier]", function () {
      const item = $(this).closest("[data-id]");
      const previous = item.prev("[data-id]");
      if (previous.length) {
        item.insertBefore(previous);
        update(maeRecipeGallery.reordered);
        item.find("[data-mae-recipe-gallery-earlier]").trigger("focus");
      }
    });
    list.on("click", "[data-mae-recipe-gallery-later]", function () {
      const item = $(this).closest("[data-id]");
      const next = item.next("[data-id]");
      if (next.length) {
        item.insertAfter(next);
        update(maeRecipeGallery.reordered);
        item.find("[data-mae-recipe-gallery-later]").trigger("focus");
      }
    });
    list.sortable({
      items: "> [data-id]",
      handle: ".mae-recipe-gallery__drag",
      placeholder: "mae-recipe-gallery__placeholder",
      forcePlaceholderSize: true,
      update: function () {
        update(maeRecipeGallery.reordered);
      },
    });
    update();
  });
})(jQuery);
