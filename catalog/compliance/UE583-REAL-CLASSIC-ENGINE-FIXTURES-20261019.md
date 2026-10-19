# UE5.8.3 genuine classic serialized package reader sample

**Verified:** `catalog/bin/verify-ue5-583-real-classic-engine-content.php` exit 0, `ok=true`. Read-only scan of the first 500 eligible `.uasset`/`.umap` files under the authentic pinned UE5.8.3 editor engine build `H:/UE5-583-runtime-probe/Engine/Content` (files larger than 40MiB excluded). Total scanned file sizes: **37,252,240 bytes**.

Results: **475 genuine UE5 classic package-summary files parsed without unexplained issues**, covering source UE5 package version values **1004 (386), 1006 (21), 1007 (1), 1008 (3), 1009 (8), 1010 (2), 1011 (1), 1012 (2), 1013 (5), 1017 (6), 1018 (40)**. **25 assets** predated the UE5 summary-version field and carry negative legacy versions (e.g. -3/-5); the dedicated UE5 reader correctly refuses to interpret those as UE5 classic serialization. They must be handled separately by compatible historical reader dispatch where supported. No other parser issues in the sample.

This is **real pinned-engine asset input regression**, not a synthetic package fixture and not yet field-by-field output comparison against Epic's native LinkerLoad. It proves the tested UE5 reader accepts actual .uasset summary/name/import/export sections across the observed version values and preserves the intended earlier-format boundary; it does not establish every version variant or native Epic semantic parity. Does not update PRD data and does not launch Satisfactory production imports.

Run on DEV with `D:/php8.5/php.exe catalog/bin/verify-ue5-583-real-classic-engine-content.php`. Requires the pinned editor engine content folder. An empty or changed fixture invalidates the recorded sample result rather than implying parity.
