const containerQueries = require("@tailwindcss/container-queries");
const fluidTailwind = require("fluid-tailwind");

const fluid = fluidTailwind.default || fluidTailwind;
const extract = fluidTailwind.extract;

/** @type {import('tailwindcss').Config} */
module.exports = {
  content: {
    files: [
      "./themes/mae-storm-eats/**/*.php",
      "./themes/mae-storm-eats/**/*.twig",
      "./themes/mae-storm-eats/assets/src/**/*.ts",
    ],
    extract: {
      DEFAULT: extract(),
    },
  },
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Josefin Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
        josefin: ['"Josefin Sans"', "ui-sans-serif", "system-ui", "sans-serif"],
        montserrat: [
          '"Montserrat"',
          "ui-sans-serif",
          "system-ui",
          "sans-serif",
        ],
      },
      colors: {
        sand: "#f6c787",
        olive: "#5e5c36",
        plum: "#996d83",
        coral: "#ee9e60",
        cream: "#fff0d2",
      },
    },
  },
  plugins: [fluid, containerQueries],
};
