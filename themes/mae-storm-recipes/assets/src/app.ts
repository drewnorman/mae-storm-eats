import { gsap } from "gsap";

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
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", bootRecipeShell, {
    once: true,
  });
} else {
  bootRecipeShell();
}
