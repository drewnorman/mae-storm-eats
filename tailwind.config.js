const containerQueries = require("@tailwindcss/container-queries");
const fluidTailwind = require("fluid-tailwind");

const fluid = fluidTailwind.default || fluidTailwind;
const extract = fluidTailwind.extract;

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: {
    files: [
      "./themes/mae-storm-recipes/**/*.php",
      "./themes/mae-storm-recipes/**/*.twig",
      "./themes/mae-storm-recipes/assets/src/**/*.ts",
    ],
    extract: {
      DEFAULT: extract(),
    },
  },
  theme: {
    extend: {
      fontFamily: {
        sans: [
          "InterVariable",
          "Inter",
          "ui-sans-serif",
          "system-ui",
          "sans-serif",
        ],
      },
    },
  },
  plugins: [fluid, containerQueries],
};
