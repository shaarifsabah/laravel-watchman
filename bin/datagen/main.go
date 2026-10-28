package main

import (
	"encoding/json"
	"os"
	"sort"

	"github.com/abadojack/whatlanggo"
	"github.com/dongri/phonenumber"
	"github.com/moov-io/iso3166"
)

func main() {
	out := map[string]any{}
	// languages in enum order
	var langs []map[string]any
	for l := whatlanggo.Lang(0); ; l++ {
		s := l.Iso6393()
		if s == "" {
			break
		}
		langs = append(langs, map[string]any{"id": int(l), "iso6391": l.Iso6391(), "iso6393": s, "name": l.String()})
	}
	out["langs"] = langs
	prof := map[string]map[string][]string{}
	for script, m := range whatlanggo.ExportProfiles() {
		pm := map[string][]string{}
		keys := []int{}
		for k := range m { keys = append(keys, int(k)) }
		sort.Ints(keys)
		for _, k := range keys { pm[whatlanggo.Lang(k).Iso6393()] = m[whatlanggo.Lang(k)] }
		prof[script] = pm
	}
	out["profiles"] = prof
	out["phone"] = phonenumber.GetISO3166()
	out["iso_alpha2"] = iso3166.ExportAlpha2()
	out["iso_alpha3"] = iso3166.ExportAlpha3()
	json.NewEncoder(os.Stdout).Encode(out)
}
