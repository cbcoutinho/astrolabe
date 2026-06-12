import { redirect } from "next/navigation";

// This is a dedicated docs subdomain (docs.astrolabecloud.com), so the root
// just sends visitors to the documentation home.
export default function RootPage() {
  redirect("/docs");
}
