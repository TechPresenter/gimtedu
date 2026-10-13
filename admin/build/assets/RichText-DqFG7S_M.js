import{j as a}from"./query-Y97U-0w1.js";import{r as i}from"./react-Dt5Z_GJD.js";import{g as o,d as h}from"./index-gF1t-mqD.js";import{L as q}from"./list-BuYJCP_m.js";import{L as D}from"./list-ordered-D5XYXI-G.js";import{L as N}from"./link-2-B2v_8jcG.js";/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const x={name:"bold",size:24,node:[["path",{d:"M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8",key:"mg9rjx"}]]};x.node;const V=o(x);/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const k={name:"code-xml",size:24,node:[["path",{d:"m18 16 4-4-4-4",key:"1inbqp"}],["path",{d:"m6 8-4 4 4 4",key:"15zrgr"}],["path",{d:"m14.5 4-5 16",key:"e7oirm"}]],aliases:["code-2"]};k.node;const C=o(k);/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const y={name:"heading-2",size:24,node:[["path",{d:"M4 12h8",key:"17cfdx"}],["path",{d:"M4 18V6",key:"1rz3zl"}],["path",{d:"M12 18V6",key:"zqpxq5"}],["path",{d:"M21 18h-4c0-4 4-3 4-6 0-1.5-2-2.5-4-1",key:"9jr5yi"}]]};y.node;const H=o(y);/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const f={name:"heading-3",size:24,node:[["path",{d:"M4 12h8",key:"17cfdx"}],["path",{d:"M4 18V6",key:"1rz3zl"}],["path",{d:"M12 18V6",key:"zqpxq5"}],["path",{d:"M17.5 10.5c1.7-1 3.5 0 3.5 1.5a2 2 0 0 1-2 2",key:"68ncm8"}],["path",{d:"M17 17.5c2 1.5 4 .3 4-1.5a2 2 0 0 0-2-2",key:"1ejuhz"}]]};f.node;const B=o(f);/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const _={name:"italic",size:24,node:[["line",{x1:"19",x2:"10",y1:"4",y2:"4",key:"15jd3p"}],["line",{x1:"14",x2:"5",y1:"20",y2:"20",key:"bu0au3"}],["line",{x1:"15",x2:"9",y1:"4",y2:"20",key:"uljnxc"}]]};_.node;const E=o(_);/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const g={name:"quote",size:24,node:[["path",{d:"M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z",key:"rib7q0"}],["path",{d:"M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z",key:"1ymkrd"}]]};g.node;const R=o(g);/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const M={name:"remove-formatting",size:24,node:[["path",{d:"M4 7V4h16v3",key:"9msm58"}],["path",{d:"M5 20h6",key:"1h6pxn"}],["path",{d:"M13 4 8 20",key:"kqq6aj"}],["path",{d:"m15 15 5 5",key:"me55sn"}],["path",{d:"m20 15-5 5",key:"11p7ol"}]]};M.node;const $=o(M);/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const v={name:"underline",size:24,node:[["path",{d:"M6 4v6a6 6 0 0 0 12 0V4",key:"9kb039"}],["line",{x1:"4",x2:"20",y1:"20",y2:"20",key:"nun2al"}]]};v.node;const T=o(v);function W({value:r,onChange:u,placeholder:w="Write here…",invalid:z,minHeight:p=180,id:b}){const s=i.useRef(null),[n,j]=i.useState(!1),c=i.useRef(r);i.useEffect(()=>{s.current&&r!==c.current&&!n&&(s.current.innerHTML=r||"",c.current=r)},[r,n]),i.useEffect(()=>{s.current&&!n&&(s.current.innerHTML=r||"")},[n]);const m=()=>{var d;const e=((d=s.current)==null?void 0:d.innerHTML)??"",l=e==="<br>"?"":e;c.current=l,u(l)},t=(e,l)=>{var d;(d=s.current)==null||d.focus(),document.execCommand(e,!1,l),m()},L=[{icon:V,label:"Bold",run:()=>t("bold")},{icon:E,label:"Italic",run:()=>t("italic")},{icon:T,label:"Underline",run:()=>t("underline")},{icon:H,label:"Heading",run:()=>t("formatBlock","H2")},{icon:B,label:"Sub heading",run:()=>t("formatBlock","H3")},{icon:q,label:"Bulleted list",run:()=>t("insertUnorderedList")},{icon:D,label:"Numbered list",run:()=>t("insertOrderedList")},{icon:R,label:"Quote",run:()=>t("formatBlock","BLOCKQUOTE")},{icon:N,label:"Insert link",run:()=>{const e=window.prompt("Link URL (https://…)");e&&t("createLink",e)}},{icon:$,label:"Clear formatting",run:()=>t("removeFormat")}];return a.jsxs("div",{className:h("overflow-hidden rounded-xl border bg-white shadow-sm dark:bg-slate-900",z?"border-red-400":"border-slate-200 dark:border-slate-700","focus-within:border-brand-500 focus-within:ring-4 focus-within:ring-brand-500/10"),children:[a.jsxs("div",{className:"flex flex-wrap items-center gap-0.5 border-b border-slate-100 bg-slate-50/70 px-1.5 py-1 dark:border-slate-800 dark:bg-slate-800/40",children:[L.map(e=>a.jsx("button",{type:"button",title:e.label,"aria-label":e.label,disabled:n,onMouseDown:l=>l.preventDefault(),onClick:e.run,className:"rounded-md p-1.5 text-slate-500 hover:bg-white hover:text-slate-900 disabled:opacity-40 dark:hover:bg-slate-700 dark:hover:text-white",children:a.jsx(e.icon,{className:"h-4 w-4"})},e.label)),a.jsx("span",{className:"mx-1 h-5 w-px bg-slate-200 dark:bg-slate-700"}),a.jsxs("button",{type:"button",onClick:()=>j(e=>!e),className:h("inline-flex items-center gap-1 rounded-md px-2 py-1.5 text-xs font-medium",n?"bg-brand-800 text-white":"text-slate-500 hover:bg-white hover:text-slate-900 dark:hover:bg-slate-700"),"aria-pressed":n,children:[a.jsx(C,{className:"h-3.5 w-3.5"})," HTML"]})]}),n?a.jsx("textarea",{id:b,value:r,onChange:e=>u(e.target.value),className:"block w-full border-0 bg-transparent p-3 font-mono text-xs text-slate-800 focus:ring-0 dark:text-slate-100",style:{minHeight:p},spellCheck:!1}):a.jsx("div",{id:b,ref:s,contentEditable:!0,role:"textbox","aria-multiline":"true","data-placeholder":w,onInput:m,onBlur:m,className:"prose-editor max-w-none overflow-y-auto px-3.5 py-3 text-sm text-slate-800 outline-none empty:before:pointer-events-none empty:before:text-slate-400 empty:before:content-[attr(data-placeholder)] dark:text-slate-100 [&_a]:text-brand-700 [&_a]:underline [&_blockquote]:border-l-4 [&_blockquote]:border-slate-200 [&_blockquote]:pl-3 [&_blockquote]:text-slate-600 [&_h2]:mb-2 [&_h2]:mt-3 [&_h2]:text-lg [&_h2]:font-bold [&_h3]:mb-1.5 [&_h3]:mt-2 [&_h3]:text-base [&_h3]:font-semibold [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-1.5 [&_ul]:list-disc [&_ul]:pl-5",style:{minHeight:p},suppressContentEditableWarning:!0})]})}export{C,R as Q,W as R};
