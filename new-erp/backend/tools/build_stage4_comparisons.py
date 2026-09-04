from pathlib import Path

from PIL import Image, ImageDraw, ImageFont


ROOT = Path(r"D:\new-erp\new-erp\backend")
REFERENCE = ROOT / "docs" / "ui-reference" / "order-to-work-order" / "step04-sku-item-matching" / "approved"
CHECK = ROOT / "docs" / "ui-check" / "order-to-work-order" / "step04-sku-item-matching"
PAGES = [
    "sku-item-relation-list",
    "sku-item-set-primary",
    "sku-item-relation-history",
    "sku-item-integrity-check",
]


def font(size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(r"C:\Windows\Fonts\msyh.ttc", size)


for page in PAGES:
    reference = Image.open(REFERENCE / f"{page}.png").convert("RGB")
    actual = Image.open(CHECK / f"{page}.png").convert("RGB")
    target = (820, 459)
    reference.thumbnail(target, Image.Resampling.LANCZOS)
    actual.thumbnail(target, Image.Resampling.LANCZOS)

    canvas = Image.new("RGB", (1680, 520), "white")
    draw = ImageDraw.Draw(canvas)
    draw.text((20, 14), "已通过 ProductDesign 基准", fill="#162033", font=font(20))
    draw.text((860, 14), "浏览器实装验收截图（1680×941）", fill="#162033", font=font(20))
    canvas.paste(reference, (20, 52))
    canvas.paste(actual, (860, 52))
    draw.line((840, 0, 840, 520), fill="#d6dde6", width=2)
    canvas.save(CHECK / f"{page}-comparison.png", quality=95)
