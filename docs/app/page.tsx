import { permanentRedirect } from "next/navigation";

// This is a dedicated docs subdomain (docs.astrolabecloud.com), so the root
// just sends visitors to the documentation home. The redirect is structural
// (not conditional), so issue a 308 permanent redirect — crawlers drop the
// root URL and follow the permanent target.
export default function RootPage() {
  permanentRedirect("/docs");
}
