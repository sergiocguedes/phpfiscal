"""Converte fontes fornecidas pela instalação em definições FPDF privadas.

Uso: python preparar-fontes-danfse.py PASTA_TTF PASTA_DESTINO
Requer fonttools. Os arquivos de fonte não são distribuídos com o projeto.
"""
import sys, pathlib, zlib
from fontTools.ttLib import TTFont

origem, destino = map(pathlib.Path, sys.argv[1:3])
destino.mkdir(parents=True, exist_ok=True)
for arquivo, nome in [('arial.ttf', 'arial'), ('arialbd.ttf', 'arialbd'), ('micross.ttf', 'micross')]:
    raw = (origem / arquivo).read_bytes()
    f = TTFont(origem / arquivo)
    if f['OS/2'].fsType & 0x0002:
        raise RuntimeError(f'Fonte {arquivo} não permite incorporação; forneça uma licença apropriada.')
    escala = 1000 / f['head'].unitsPerEm
    n = lambda v: round(v * escala)
    cmap = f.getBestCmap()
    widths = []
    for i in range(256):
        try:
            ch = bytes([i]).decode('cp1252')
        except UnicodeDecodeError:
            ch = '?'
        glyph = cmap.get(ord(ch), '.notdef')
        widths.append(f'{i}=>{n(f["hmtx"][glyph][0])}')
    psname = f['name'].getDebugName(6).replace("'", '')
    head, hhea, post = f['head'], f['hhea'], f['post']
    bbox = ' '.join(str(n(getattr(head, k))) for k in ['xMin', 'yMin', 'xMax', 'yMax'])
    desc = f"array('Ascent'=>{n(hhea.ascent)},'Descent'=>{n(hhea.descent)},'CapHeight'=>{n(getattr(f['OS/2'], 'sCapHeight', hhea.ascent))},'Flags'=>32,'FontBBox'=>'[{bbox}]','ItalicAngle'=>{post.italicAngle},'StemV'=>70,'MissingWidth'=>{n(f['hmtx']['.notdef'][0])})"
    cw = ','.join(f'chr({i})=>{widths[i].split("=>")[1]}' for i in range(256))
    php = f"<?php\n$type='TrueType';\n$name='{psname}';\n$desc={desc};\n$up={n(post.underlinePosition)};\n$ut={n(post.underlineThickness)};\n$cw=array({cw});\n$enc='cp1252';\n$diff='';\n$file='{nome}.z';\n$originalsize={len(raw)};\n"
    (destino / (nome + '.php')).write_text(php, encoding='utf-8')
    (destino / (nome + '.z')).write_bytes(zlib.compress(raw))
    print(f'{arquivo}: definição preparada em {destino}')
