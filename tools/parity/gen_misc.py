import json, random, csv
import os, tempfile
OUT = os.environ.get('PARITY_OUT', tempfile.gettempdir())
GO_SRC = os.environ.get('WATCHMAN_GO_SRC', os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', 'watchman'))
random.seed(7)
go = json.load(open(os.path.join(OUT, 'go_ofac.json'))) + json.load(open(os.path.join(OUT, 'go_csl.json')))
countries=set(); phones=set(); sites=set(); emails=set(); addrs=set(); names=[]
for x in go:
    e=x['entity']
    for a in e.get('addresses') or []:
        countries.add(a.get('country','')); 
        addrs.add(', '.join(v for v in [a.get('line1',''), a.get('city',''), a.get('state',''), a.get('postalCode','')] if v))
    c=e.get('contact') or {}
    phones.update(c.get('phoneNumbers') or []); phones.update(c.get('faxNumbers') or [])
    sites.update(c.get('websites') or []); emails.update(c.get('emailAddresses') or [])
    names.append((e['name'], e['entityType']))
    for k in ['person','business','organization','vessel','aircraft']:
        if e.get(k): 
            for a in e[k].get('altNames') or []: names.append((a, e['entityType']))
# EU names (incl non-Latin)
r = csv.reader(open(os.path.join(GO_SRC, 'test/testdata/eu_csl.csv'), encoding='utf-8'), delimiter=';')
hdr = next(r)
for row in r:
    for v in row:
        if v and any(ord(ch)>127 for ch in v) and len(v) < 80: names.append((v, random.choice(['person','business','vessel'])))
countries |= {'US','USA','UK','gb','Iran','Korea, North','Russian Federation','Côte d’Ivoire','turkey','Cote dIvoire','DE','DEU','Viet Nam',''}
names = random.sample(names, min(25000, len(names)))
norm = []
cl = list(countries); pl=list(phones); sl=list(sites)+['http://user@www.Example.COM:443/a?b','mail.suex.io','192.168.1.1','co.uk']; el=list(emails)+['x@gmail.com','y@sub.example.co.jp']
for i in range(max(len(cl),len(pl),len(sl),len(el))):
    norm.append({'country': cl[i%len(cl)], 'phone': pl[i%len(pl)], 'website': sl[i%len(sl)], 'email': el[i%len(el)]})
json.dump(norm, open(os.path.join(OUT, 'norm_in.json'),'w'))
al = list(addrs)[:3000] + ['123 N Main Street Apt 4B\nSpringfield, IL 62704', '1600 Pennsylvania Avenue NW, Washington, DC 20500', 'PO Box 55\nNew York, NY 10001-1234 US', 'RR 2 Box 152\nShelbyville TN 37160', '10 Downing St London SW1A 2AA UK', 'C/O John\n500 W 5TH ST STE 100\nAUSTIN TX 78701']
json.dump(al, open(os.path.join(OUT, 'addr_in.json'),'w'))
json.dump([{'name': n, 'type': t} for n,t in names], open(os.path.join(OUT, 'prep_in.json'),'w'))
toks = [t for n,_ in names[:3000] for t in n.lower().split()]
jw = [{'a': ' '.join(random.sample(toks, random.randint(1,4))), 'b': ' '.join(random.sample(toks, random.randint(1,4)))} for _ in range(5000)]
json.dump(jw, open(os.path.join(OUT, 'jw_in.json'),'w'))
print(len(norm), len(al), len(names), len(jw))
