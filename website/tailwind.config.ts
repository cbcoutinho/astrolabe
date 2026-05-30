import type { Config } from "tailwindcss";

export default {
  content: ["./src/**/*.{ts,tsx}"],
  theme: {
    extend: {
      fontFamily: {
        sans: [
          "var(--font-inter)",
          "ui-sans-serif",
          "system-ui",
          "sans-serif",
        ],
        mono: [
          "var(--font-jetbrains-mono)",
          "ui-monospace",
          "SFMono-Regular",
          "monospace",
        ],
      },
      colors: {
        brand: {
          50: "#e6f3fa",
          100: "#cce6f4",
          200: "#99cee9",
          300: "#66b5df",
          400: "#339cd4",
          500: "#0082c9",
          600: "#006aa3",
          700: "#00517c",
          800: "#003855",
          900: "#001f2f",
        },
        ink: {
          DEFAULT: "#0b1320",
          900: "#0b1320",
          800: "#172033",
          700: "#1f2a44",
        },
      },
      boxShadow: {
        card: "0 1px 2px 0 rgb(15 23 42 / 0.04), 0 1px 3px 0 rgb(15 23 42 / 0.06)",
        "card-hover":
          "0 4px 6px -1px rgb(15 23 42 / 0.06), 0 2px 4px -2px rgb(15 23 42 / 0.08)",
      },
    },
  },
  plugins: [],
} satisfies Config;
