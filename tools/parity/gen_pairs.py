import json, random
import os, tempfile
OUT = os.environ.get('PARITY_OUT', tempfile.gettempdir())
GO_SRC = os.environ.get('WATCHMAN_GO_SRC', os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..', '..', 'watchman'))
random.seed(42)
go = json.load(open(os.path.join(OUT, 'go_ofac.json')))
csl = json.load(open(os.path.join(OUT, 'go_csl.json')))
ents = [x['entity'] for x in go] + [x['entity'] for x in csl][:3000]
def typo(s):
    if len(s) < 4: return s
    i = random.randrange(1, len(s)-1)
    op = random.choice(['swap','del','sub','dup'])
    if op=='swap': return s[:i]+s[i+1]+s[i]+s[i+2:]
    if op=='del': return s[:i]+s[i+1:]
    if op=='sub': return s[:i]+random.choice('aeioustrnl')+s[i+1:]
    return s[:i]+s[i]+s[i:]
def variant(name):
    toks = name.split()
    r = random.random()
    if r < 0.2: return name
    if r < 0.4: return ' '.join(typo(t) for t in toks)
    if r < 0.55 and len(toks) > 1: random.shuffle(toks); return ' '.join(toks)
    if r < 0.7 and len(toks) > 2: return ' '.join(toks[:1]+toks[-1:])
    if r < 0.8: return name.lower()
    if r < 0.9: return typo(name)
    return random.choice(ents)['name']
pairs = []
for _ in range(6000):
    idx = random.choice(ents)
    if random.random() < 0.15:
        idx2 = random.choice(ents)
    else:
        idx2 = idx
    t = idx['entityType']
    qt = t if random.random() < 0.85 else random.choice(['person','business','organization','vessel','aircraft',''])
    q = {'name': variant(idx['name']), 'entityType': qt, 'sourceList': 'api-request'}
    sub = None
    if qt == 'person':
        sub = {'name': q['name']}
        p = idx.get('person') or {}
        if p.get('birthDate') and random.random() < 0.5:
            bd = p['birthDate']
            if random.random() < 0.5:
                y = int(bd[:4]) + random.choice([-2,-1,0,1,3,7])
                bd = '%04d%s' % (max(y,1), bd[4:])
            sub['birthDate'] = bd
        if p.get('governmentIDs') and random.random() < 0.4:
            g = random.choice(p['governmentIDs'])
            gid = dict(g)
            if random.random() < 0.3: gid['identifier'] = gid['identifier'][:-1] + 'X'
            if random.random() < 0.3: gid['country'] = random.choice(['', 'RU', gid.get('country','')])
            sub['governmentIDs'] = [gid]
        if p.get('titles') and random.random() < 0.3:
            sub['titles'] = [random.choice(p['titles'])]
        if random.random() < 0.2:
            sub['altNames'] = [variant(random.choice(ents)['name'])]
        q['person'] = sub
    elif qt in ('business','organization'):
        sub = {'name': q['name']}
        b = idx.get(qt) or idx.get('business') or idx.get('organization') or {}
        if b.get('governmentIDs') and random.random() < 0.4:
            sub['governmentIDs'] = [random.choice(b['governmentIDs'])]
        q[qt] = sub
    elif qt == 'vessel':
        v = idx.get('vessel') or {}
        sub = {'name': q['name']}
        if v.get('imoNumber') and random.random() < 0.5: sub['imoNumber'] = v['imoNumber'] if random.random()<0.7 else '1234567'
        if v.get('callSign') and random.random() < 0.3: sub['callSign'] = v['callSign']
        q['vessel'] = sub
    elif qt == 'aircraft':
        a = idx.get('aircraft') or {}
        sub = {'name': q['name']}
        if a.get('serialNumber') and random.random() < 0.5: sub['serialNumber'] = a['serialNumber']
        q['aircraft'] = sub
    if idx.get('addresses') and random.random() < 0.3:
        a = dict(random.choice(idx['addresses']))
        if random.random() < 0.5: a['line1'] = variant(a.get('line1',''))
        q['addresses'] = [a]
    if idx.get('cryptoAddresses') and random.random() < 0.5:
        q['cryptoAddresses'] = [random.choice(idx['cryptoAddresses'])]
    c = idx.get('contact') or {}
    if c.get('emailAddresses') and random.random() < 0.4:
        q['contact'] = {'emailAddresses': [random.choice(c['emailAddresses'])]}
    if c.get('phoneNumbers') and random.random() < 0.4:
        q.setdefault('contact', {})['phoneNumbers'] = [random.choice(c['phoneNumbers'])]
    if c.get('websites') and random.random() < 0.4:
        q.setdefault('contact', {})['websites'] = [random.choice(c['websites'])]
    if random.random() < 0.02:
        q['sourceID'] = idx2['sourceID']
    pairs.append({'query': q, 'index': idx2})
json.dump(pairs, open(os.path.join(OUT, 'pairs.json'), 'w'))
print(len(pairs))
