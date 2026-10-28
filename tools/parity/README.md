# Parity checks against the Go implementation

These scripts compare this PHP port with moov-io/watchman's Go code on the same inputs.
They are development tools, not part of the package runtime.

## 1. Build the Go harness

`harness_main.go` is a small CLI built from a copy of watchman's `pkg/search`,
`internal/{prepare,norm,stringscore,tfidf}`, `pkg/usaddress`, `pkg/address` and the
`ofac`, `csl_us`, `senzing`, `fincen_311` sources (import paths rewritten to `parity/...`;
telemetry/logging replaced with no-op stubs). Put it in a module next to those copies
and build with Go ≥ 1.26 (`build.sh` shows the flags used offline).

Subcommands (JSON on stdin/stdout): `prepare`, `jw`, `similarity`, `norm`, `address`,
`ofac <dir>`, `csl <file>`, `senzing <file> <source>`, `fincen <file>`.

## 2. Produce Go outputs and compare

```bash
export PARITY_OUT=/tmp                  # where the Go JSON outputs are written and read
export WATCHMAN_GO_SRC=$PWD/watchman    # your moov-io/watchman checkout
export FINCEN_311_HTML=/path/to/fincen_311.html   # a saved copy of the FinCEN 311 page (others.php)
export WATCHMAN_AUTOLOAD=/path/to/vendor/autoload.php   # optional: autoloader to use

./parity ofac  watchman/pkg/sources/ofac/testdata          > $PARITY_OUT/go_ofac.json
./parity csl   watchman/pkg/sources/csl_us/testdata/consolidated.csv > $PARITY_OUT/go_csl.json
./parity senzing watchman/test/testdata/opensanctions/us_congress_senzing.json us_congress > $PARITY_OUT/go_sz.json
python3 gen_pairs.py && ./parity similarity < $PARITY_OUT/pairs.json > $PARITY_OUT/go_sim.json
python3 gen_misc.py  && ./parity norm < $PARITY_OUT/norm_in.json > $PARITY_OUT/go_norm.json \
  && ./parity address < $PARITY_OUT/addr_in.json > $PARITY_OUT/go_addr.json \
  && ./parity prepare < $PARITY_OUT/prep_in.json > $PARITY_OUT/go_prep.json \
  && ./parity jw < $PARITY_OUT/jw_in.json > $PARITY_OUT/go_jw.json

php -d memory_limit=4G ofac.php
php -d memory_limit=4G others.php
php -d memory_limit=4G sim.php
php -d memory_limit=4G misc.php
```

## Results at release (upstream 7f44b4b6)

| Check | Cases | Identical |
|---|---|---|
| OFAC SDN entities + prepared fields | 16,989 | 16,986 (3 = Go language-detection ties) |
| US CSL entities | 5,512 | 5,512 |
| Senzing / OpenSanctions entities | 1,322 | 1,322 |
| FinCEN 311 entities | 3 | 3 |
| Similarity (final score + 9 pieces), randomized queries | 6,000 | 6,000 (bit-exact) |
| Name preparation (incl. non-Latin EU names) | 25,000 | 24,999 (1 = tie) |
| Jaro-Winkler variants | 15,000 | 15,000 |
| Country / phone / website / domain normalization | 6,285 | 6,285 |
| US address parsing | 3,006 | 3,006 |

The only differences come from whatlanggo language-detection *ties* (confidence 0), where Go
picks a winner by random map iteration order (it can differ between two Go runs); the PHP port
deterministically prefers English, then ISO code order.
