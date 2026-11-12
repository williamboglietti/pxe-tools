"""
Chiffrement symétrique authentifié via libsodium secretbox
(XSalsa20 + Poly1305). Mirror Python du helper PHP
app/src/Util/Crypto.php pour interop cross-language.

Format DB : base64(nonce(24B) || ciphertext+mac).

À distinguer de Admin\\Util\\SecretBox (PHP) qui dérive la clé d'une
passphrase via Argon2id : ici la clé maître est lue depuis disque
(/etc/pxe-tools/master.key) pour un usage non-interactif.
"""
import base64
import os

from nacl.secret import SecretBox
from nacl.utils import random as nacl_random


DEFAULT_KEY_PATH = "/etc/pxe-tools/master.key"


def _key_path() -> str:
    return os.environ.get("PXE_MASTER_KEY_PATH") or DEFAULT_KEY_PATH


def _load_key() -> bytes:
    path = _key_path()
    try:
        with open(path, "rb") as f:
            key = f.read()
    except (FileNotFoundError, PermissionError) as e:
        raise RuntimeError(f"Master key absente ou illisible : {path}") from e
    if len(key) != SecretBox.KEY_SIZE:
        raise RuntimeError(
            f"Master key invalide (taille {len(key)}, attendu {SecretBox.KEY_SIZE}) : {path}"
        )
    return key


def encrypt(plaintext: str) -> str:
    key = _load_key()
    box = SecretBox(key)
    nonce = nacl_random(SecretBox.NONCE_SIZE)
    ciphertext = box.encrypt(plaintext.encode("utf-8"), nonce).ciphertext
    return base64.b64encode(nonce + ciphertext).decode("ascii")


def decrypt(b64: str) -> str:
    key = _load_key()
    raw = base64.b64decode(b64, validate=True)
    if len(raw) < SecretBox.NONCE_SIZE + 16:
        raise RuntimeError("Ciphertext invalide (taille)")
    nonce = raw[: SecretBox.NONCE_SIZE]
    ciphertext = raw[SecretBox.NONCE_SIZE :]
    box = SecretBox(key)
    try:
        plaintext = box.decrypt(ciphertext, nonce)
    except Exception as e:
        raise RuntimeError("Decrypt failed (mauvais key ou ciphertext tampered)") from e
    return plaintext.decode("utf-8")
