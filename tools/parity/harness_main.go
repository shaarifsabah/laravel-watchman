// Command parity is a JSON-in/JSON-out harness around Moov Watchman's scoring,
// normalization and source-mapping code, used to compare a port against the
// reference Go implementation.
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"

	"parity/internal/norm"
	"parity/internal/prepare"
	"parity/internal/stringscore"
	"parity/pkg/address"
	"parity/pkg/download"
	"parity/pkg/search"
	"parity/pkg/sources/csl_us"
	"parity/pkg/sources/fincen_311"
	"parity/pkg/sources/ofac"
	"parity/pkg/sources/senzing"

	"github.com/xrash/smetrics"
)

const usage = `usage: parity <command> [args] < input.json

commands (JSON on stdin, JSON on stdout):
  prepare                       [{"name":"...","type":"person|business|organization|vessel|aircraft|"}]
  jw                            [{"a":"...","b":"..."}]
  similarity                    [{"query":<Entity>,"index":<Entity>}]
  norm                          [{"country":"...","phone":"...","website":"...","email":"..."}]
  address                       ["free form address", ...]
  ofac <dir>                    dir holds add.csv, alt.csv, sdn.csv, sdn_comments.csv
  csl <consolidated.csv>
  senzing <file.json|jsonl> <sourceList>
  fincen <file.html>
`

func main() {
	if len(os.Args) < 2 {
		fmt.Fprint(os.Stderr, usage)
		os.Exit(2)
	}
	var out any
	var err error
	args := os.Args[2:]
	switch os.Args[1] {
	case "prepare":
		out, err = cmdPrepare(os.Stdin)
	case "jw":
		out, err = cmdJW(os.Stdin)
	case "similarity":
		out, err = cmdSimilarity(os.Stdin)
	case "norm":
		out, err = cmdNorm(os.Stdin)
	case "address":
		out, err = cmdAddress(os.Stdin)
	case "ofac":
		out, err = cmdOFAC(args)
	case "csl":
		out, err = cmdCSL(args)
	case "senzing":
		out, err = cmdSenzing(args)
	case "fincen":
		out, err = cmdFincen(args)
	default:
		fmt.Fprint(os.Stderr, usage)
		os.Exit(2)
	}
	if err != nil {
		fmt.Fprintf(os.Stderr, "ERROR: %v\n", err)
		os.Exit(1)
	}
	enc := json.NewEncoder(os.Stdout)
	enc.SetEscapeHTML(false)
	enc.SetIndent("", "  ")
	if err := enc.Encode(out); err != nil {
		fmt.Fprintf(os.Stderr, "ERROR encoding output: %v\n", err)
		os.Exit(1)
	}
}

func decode[T any](r io.Reader) (T, error) {
	var v T
	dec := json.NewDecoder(r)
	if err := dec.Decode(&v); err != nil {
		return v, fmt.Errorf("decoding stdin: %w", err)
	}
	return v, nil
}

// ---- prepare ----

type prepareIn struct {
	Name string `json:"name"`
	Type string `json:"type"`
}

type preparedNames struct {
	Name                 string
	NameFields           []string
	AltNames             []string
	AltNameFields        [][]string
	HistoricalNames      []string
	HistoricalNameFields [][]string
}

type prepareOut struct {
	Input                     prepareIn     `json:"input"`
	Prepared                  preparedNames `json:"prepared"`
	LowerAndRemovePunctuation string        `json:"lowerAndRemovePunctuation"`
	RemoveStopwords           string        `json:"removeStopwords"`
}

func cmdPrepare(r io.Reader) (any, error) {
	in, err := decode[[]prepareIn](r)
	if err != nil {
		return nil, err
	}
	out := make([]prepareOut, 0, len(in))
	for _, x := range in {
		e := search.Entity[search.Value]{Name: x.Name, Type: search.EntityType(x.Type)}.Normalize()
		pf := e.PreparedFields
		lower := prepare.LowerAndRemovePunctuation(x.Name)
		out = append(out, prepareOut{
			Input: x,
			Prepared: preparedNames{
				Name:                 pf.Name,
				NameFields:           pf.NameFields,
				AltNames:             pf.AltNames,
				AltNameFields:        pf.AltNameFields,
				HistoricalNames:      pf.HistoricalNames,
				HistoricalNameFields: pf.HistoricalNameFields,
			},
			LowerAndRemovePunctuation: lower,
			RemoveStopwords:           prepare.RemoveStopwords(lower),
		})
	}
	return out, nil
}

// ---- jw ----

type jwIn struct {
	A string `json:"a"`
	B string `json:"b"`
}

type jwOut struct {
	Input                          jwIn    `json:"input"`
	SmetricsJaroWinkler            float64 `json:"smetricsJaroWinkler"`
	BestPairCombinationJaroWinkler float64 `json:"bestPairCombinationJaroWinkler"`
	StringscoreJaroWinkler         float64 `json:"stringscoreJaroWinkler"`
}

func cmdJW(r io.Reader) (any, error) {
	in, err := decode[[]jwIn](r)
	if err != nil {
		return nil, err
	}
	out := make([]jwOut, 0, len(in))
	for _, x := range in {
		out = append(out, jwOut{
			Input:                          x,
			SmetricsJaroWinkler:            smetrics.JaroWinkler(x.A, x.B, 0.7, 4),
			BestPairCombinationJaroWinkler: stringscore.BestPairCombinationJaroWinkler(strings.Fields(x.A), strings.Fields(x.B)),
			StringscoreJaroWinkler:         stringscore.JaroWinkler(x.A, x.B),
		})
	}
	return out, nil
}

// ---- similarity ----

type similarityIn struct {
	Query search.Entity[search.Value] `json:"query"`
	Index search.Entity[search.Value] `json:"index"`
}

type similarityOut struct {
	Similarity float64                `json:"similarity"`
	Detailed   search.SimilarityScore `json:"detailed"`
}

func cmdSimilarity(r io.Reader) (any, error) {
	in, err := decode[[]similarityIn](r)
	if err != nil {
		return nil, err
	}
	out := make([]similarityOut, 0, len(in))
	for _, x := range in {
		q := x.Query.Normalize()
		i := x.Index.Normalize()
		out = append(out, similarityOut{
			Similarity: search.Similarity(q, i),
			Detailed:   search.DetailedSimilarity(nil, q, i),
		})
	}
	return out, nil
}

// ---- norm ----

type normIn struct {
	Country string `json:"country"`
	Phone   string `json:"phone"`
	Website string `json:"website"`
	Email   string `json:"email"`
}

type normOut struct {
	Input               normIn   `json:"input"`
	Country             string   `json:"country"`
	PhoneNumber         string   `json:"phoneNumber"`
	Website             string   `json:"website"`
	DomainKeys          []string `json:"domainKeys"`
	DomainKeysFromEmail []string `json:"domainKeysFromEmail"`
}

func cmdNorm(r io.Reader) (any, error) {
	in, err := decode[[]normIn](r)
	if err != nil {
		return nil, err
	}
	out := make([]normOut, 0, len(in))
	for _, x := range in {
		out = append(out, normOut{
			Input:               x,
			Country:             norm.Country(x.Country),
			PhoneNumber:         norm.PhoneNumber(x.Phone),
			Website:             norm.Website(x.Website),
			DomainKeys:          norm.DomainKeys(x.Website),
			DomainKeysFromEmail: norm.DomainKeysFromEmail(x.Email),
		})
	}
	return out, nil
}

// ---- address ----

type addressOut struct {
	Input  string         `json:"input"`
	Parsed search.Address `json:"parsed"`
}

func cmdAddress(r io.Reader) (any, error) {
	in, err := decode[[]string](r)
	if err != nil {
		return nil, err
	}
	ctx := context.Background()
	out := make([]addressOut, 0, len(in))
	for _, s := range in {
		out = append(out, addressOut{Input: s, Parsed: address.ParseAddress(ctx, s)})
	}
	return out, nil
}

// ---- source mappers ----

type entityOut struct {
	Entity   search.Entity[search.Value] `json:"entity"`
	Prepared search.PreparedFields       `json:"prepared"`
}

func entityOutputs(entities []search.Entity[search.Value]) []entityOut {
	out := make([]entityOut, 0, len(entities))
	for _, e := range entities {
		out = append(out, entityOut{Entity: e, Prepared: e.PreparedFields})
	}
	return out
}

func openFiles(keyed map[string]string) (download.Files, error) {
	files := make(download.Files, len(keyed))
	for key, path := range keyed {
		f, err := os.Open(path)
		if err != nil {
			files.Close()
			return nil, err
		}
		files[key] = f
	}
	return files, nil
}

func cmdOFAC(args []string) (any, error) {
	if len(args) < 1 {
		return nil, fmt.Errorf("ofac: directory argument required")
	}
	keyed := map[string]string{}
	for _, name := range []string{"add.csv", "alt.csv", "sdn.csv", "sdn_comments.csv"} {
		p := filepath.Join(args[0], name)
		if _, err := os.Stat(p); err == nil {
			keyed[name] = p
		}
	}
	files, err := openFiles(keyed)
	if err != nil {
		return nil, err
	}
	defer files.Close()
	res, err := ofac.Read(files)
	if err != nil {
		return nil, err
	}
	return entityOutputs(ofac.GroupIntoEntities(res.SDNs, res.Addresses, res.SDNComments, res.AlternateIdentities)), nil
}

func cmdCSL(args []string) (any, error) {
	if len(args) < 1 {
		return nil, fmt.Errorf("csl: path to consolidated.csv required")
	}
	// csl_us.Read dispatches on the file name, which must be consolidated.csv.
	files, err := openFiles(map[string]string{"consolidated.csv": args[0]})
	if err != nil {
		return nil, err
	}
	defer files.Close()
	data, err := csl_us.Read(files)
	if err != nil {
		return nil, err
	}
	return entityOutputs(csl_us.ConvertSanctionsData(data)), nil
}

func cmdSenzing(args []string) (any, error) {
	if len(args) < 2 {
		return nil, fmt.Errorf("senzing: <path> <sourceList> required")
	}
	f, err := os.Open(args[0])
	if err != nil {
		return nil, err
	}
	defer f.Close()
	entities, err := senzing.ReadEntities(f, search.SourceList(args[1]))
	if err != nil {
		return nil, err
	}
	return entityOutputs(entities), nil
}

func cmdFincen(args []string) (any, error) {
	if len(args) < 1 {
		return nil, fmt.Errorf("fincen: path to html file required")
	}
	// fincen_311.Read only accepts a file key containing "fincen_311".
	files, err := openFiles(map[string]string{"fincen_311.html": args[0]})
	if err != nil {
		return nil, err
	}
	defer files.Close()
	data, err := fincen_311.Read(files)
	if err != nil {
		return nil, err
	}
	return entityOutputs(fincen_311.ConvertSpecialMeasures(data)), nil
}
