// Player Kick: the 100 players ready in the menu (name on the board, c = national team: the shirt colours).
// The owner adds the photos himself (menu → 👥 Players & photos); he can rename, delete and add more players.
// shirt numbers (the owner can change them in the menu)
const NUM = { messi: 10, ronaldo: 7, neymar: 10, mbappe: 10, haaland: 9, vinicius: 7, yamal: 19, salah: 10, bellingham: 10, kane: 9, dimaria: 11, emartinez: 23, alvarez: 9, lautaro: 22, macallister: 20, enzo: 24, depaul: 7, otamendi: 19, garnacho: 17, rodrygo: 11, raphinha: 11, endrick: 9, alisson: 1, marquinhos: 4, casemiro: 5, guimaraes: 8, militao: 3, savinho: 20, brunof: 8, bernardo: 10, leao: 17, rdias: 4, felix: 11, vitinha: 23, jneves: 15, nmendes: 19, griezmann: 7, dembele: 11, kante: 13, camavinga: 6, tchouameni: 8, saliba: 17, maignan: 16, olise: 11, theo: 22, saka: 7, foden: 11, rice: 4, palmer: 10, rashford: 11, trent: 12, pickford: 1, stones: 5, pedri: 8, gavi: 9, rodri: 16, nico: 17, morata: 7, olmo: 10, unai: 23, carvajal: 2, musiala: 10, wirtz: 17, kimmich: 6, havertz: 7, rudiger: 2, neuer: 1, sane: 19, gundogan: 21, vandijk: 4, dejong: 21, gakpo: 11, depay: 10, debruyne: 7, courtois: 1, lukaku: 10, doku: 22, modric: 10, gvardiol: 4, kovacic: 8, lewandowski: 9, szczesny: 1, hakimi: 2, ziyech: 7, bounou: 1, brahim: 10, son: 7, kvara: 7, osimhen: 9, mane: 10, valverde: 15, nunez: 19, odegaard: 10, lookman: 18, kubo: 20, pulisic: 10, davies: 19, mitoma: 7, isak: 9, gyokeres: 17 };
export const PLAYERS = [
  // ⭐ superstars
  ['messi', 'MESSI', 'ar'], ['ronaldo', 'RONALDO', 'pt'], ['neymar', 'NEYMAR', 'br'], ['mbappe', 'MBAPPÉ', 'fr'], ['haaland', 'HAALAND', 'no'],
  ['vinicius', 'VINÍCIUS JR', 'br'], ['yamal', 'LAMINE YAMAL', 'es'], ['salah', 'SALAH', 'eg'], ['bellingham', 'BELLINGHAM', 'gb'], ['kane', 'KANE', 'gb'],
  // 🇦🇷 Argentina
  ['dimaria', 'DI MARÍA', 'ar'], ['emartinez', 'E. MARTÍNEZ', 'ar'], ['alvarez', 'J. ÁLVAREZ', 'ar'], ['lautaro', 'LAUTARO', 'ar'], ['macallister', 'MAC ALLISTER', 'ar'],
  ['enzo', 'ENZO', 'ar'], ['depaul', 'DE PAUL', 'ar'], ['otamendi', 'OTAMENDI', 'ar'], ['garnacho', 'GARNACHO', 'ar'],
  // 🇧🇷 Brazil
  ['rodrygo', 'RODRYGO', 'br'], ['raphinha', 'RAPHINHA', 'br'], ['endrick', 'ENDRICK', 'br'], ['alisson', 'ALISSON', 'br'], ['marquinhos', 'MARQUINHOS', 'br'],
  ['casemiro', 'CASEMIRO', 'br'], ['guimaraes', 'B. GUIMARÃES', 'br'], ['militao', 'MILITÃO', 'br'], ['savinho', 'SAVINHO', 'br'],
  // 🇵🇹 Portugal
  ['brunof', 'B. FERNANDES', 'pt'], ['bernardo', 'BERNARDO', 'pt'], ['leao', 'LEÃO', 'pt'], ['rdias', 'RÚBEN DIAS', 'pt'], ['felix', 'JOÃO FÉLIX', 'pt'],
  ['vitinha', 'VITINHA', 'pt'], ['jneves', 'JOÃO NEVES', 'pt'], ['nmendes', 'NUNO MENDES', 'pt'],
  // 🇫🇷 France
  ['griezmann', 'GRIEZMANN', 'fr'], ['dembele', 'DEMBÉLÉ', 'fr'], ['kante', 'KANTÉ', 'fr'], ['camavinga', 'CAMAVINGA', 'fr'], ['tchouameni', 'TCHOUAMÉNI', 'fr'],
  ['saliba', 'SALIBA', 'fr'], ['maignan', 'MAIGNAN', 'fr'], ['olise', 'OLISE', 'fr'], ['theo', 'THÉO', 'fr'],
  // 🏴 England
  ['saka', 'SAKA', 'gb'], ['foden', 'FODEN', 'gb'], ['rice', 'RICE', 'gb'], ['palmer', 'PALMER', 'gb'], ['rashford', 'RASHFORD', 'gb'],
  ['trent', 'ALEXANDER-ARNOLD', 'gb'], ['pickford', 'PICKFORD', 'gb'], ['stones', 'STONES', 'gb'],
  // 🇪🇸 Spain
  ['pedri', 'PEDRI', 'es'], ['gavi', 'GAVI', 'es'], ['rodri', 'RODRI', 'es'], ['nico', 'NICO WILLIAMS', 'es'], ['morata', 'MORATA', 'es'],
  ['olmo', 'DANI OLMO', 'es'], ['unai', 'UNAI SIMÓN', 'es'], ['carvajal', 'CARVAJAL', 'es'],
  // 🇩🇪 Germany
  ['musiala', 'MUSIALA', 'de'], ['wirtz', 'WIRTZ', 'de'], ['kimmich', 'KIMMICH', 'de'], ['havertz', 'HAVERTZ', 'de'], ['rudiger', 'RÜDIGER', 'de'],
  ['neuer', 'NEUER', 'de'], ['sane', 'SANÉ', 'de'], ['gundogan', 'GÜNDOĞAN', 'de'],
  // 🇳🇱 🇧🇪 🇭🇷 🇵🇱 🇲🇦
  ['vandijk', 'VAN DIJK', 'nl'], ['dejong', 'DE JONG', 'nl'], ['gakpo', 'GAKPO', 'nl'], ['depay', 'DEPAY', 'nl'],
  ['debruyne', 'DE BRUYNE', 'be'], ['courtois', 'COURTOIS', 'be'], ['lukaku', 'LUKAKU', 'be'], ['doku', 'DOKU', 'be'],
  ['modric', 'MODRIĆ', 'hr'], ['gvardiol', 'GVARDIOL', 'hr'], ['kovacic', 'KOVAČIĆ', 'hr'],
  ['lewandowski', 'LEWANDOWSKI', 'pl'], ['szczesny', 'SZCZĘSNY', 'pl'],
  ['hakimi', 'HAKIMI', 'ma'], ['ziyech', 'ZIYECH', 'ma'], ['bounou', 'BOUNOU', 'ma'], ['brahim', 'BRAHIM DÍAZ', 'ma'],
  // 🌍 more fan favourites
  ['son', 'SON', 'kr'], ['kvara', 'KVARATSKHELIA', 'ge'], ['osimhen', 'OSIMHEN', 'ng'], ['mane', 'MANÉ', 'sn'], ['valverde', 'VALVERDE', 'uy'],
  ['nunez', 'NÚÑEZ', 'uy'], ['odegaard', 'ØDEGAARD', 'no'], ['lookman', 'LOOKMAN', 'ng'], ['kubo', 'KUBO', 'jp'], ['pulisic', 'PULISIC', 'us'],
  ['davies', 'A. DAVIES', 'ca'], ['mitoma', 'MITOMA', 'jp'], ['isak', 'ISAK', 'se'], ['gyokeres', 'GYÖKERES', 'se'],
].map(([id, name, c]) => ({ id, name, c, n: NUM[id] || 10 }));
export const DEFAULT_SIX = ['messi', 'ronaldo', 'neymar', 'mbappe', 'haaland', 'vinicius'];
