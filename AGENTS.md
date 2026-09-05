uni-oc3 е единственият write target.

## WRITE SCOPE

ONLY this repository is writable:

```text
uni-oc3
```

## READ-ONLY REFERENCES

The following are reference-only and MUST NOT be modified (no edit/create/delete/format/stage/commit):

```text
reference-uni-oc4
reference-jet-oc3
reference-oc3-store
reference-oc3-core
uni.avalonbg.com
```

`uni.avalonbg.com` is a LOCAL reference copy of the Control Panel for inspection/comparison only.
It is NOT the active CP development repository in this workspace.

If a future task appears to require a change outside `uni-oc3`:

```text
STOP
report the proposed external change
do not implement it
```

## Reference roles

- `reference-uni-oc4` — функционалният reference.
- `reference-jet-oc3` — OC3 implementation/reference pattern.
- `reference-oc3-core` — platform reference за OpenCart 3.x.
- `reference-oc3-store` — optional runtime reference, но не specification.

## Platform notes

Target е OpenCart 3.x family, не само 3.0.3.9.
Да не се правят store-specific assumptions.
Journal compatibility да се извлече от доказания asset-loading pattern в reference-jet-oc3.
Да не се модифицират reference директориите.
