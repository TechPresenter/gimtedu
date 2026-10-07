import{a as d}from"./react-Cz5LWLod.js";/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const S=t=>t==null?void 0:t.replace(/([a-z0-9])([A-Z])/g,"$1-$2").toLowerCase();/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */function D(t,e,n=[]){if(e==null)throw new Error("[lucide]: iconNode is required when icon name is used");return{name:S(t),size:24,node:e,...n.length>0?{aliases:n}:{}}}/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const F=t=>{let e="",n=!1;for(const o of t){if(o==="-"||o==="_"||o<=" "){n=e.length>0;continue}e.length===0?e+=o.toLowerCase():e+=n?o.toUpperCase():o,n=!1}return e};/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const U=t=>{const e=F(t);return e.charAt(0).toUpperCase()+e.slice(1)};/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const W=(...t)=>t.filter((e,n,o)=>!!e&&e.trim()!==""&&o.indexOf(e)===n).join(" ").trim();/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const a={xmlns:"http://www.w3.org/2000/svg",width:24,height:24,viewBox:"0 0 24 24",fill:"none",stroke:"currentColor","stroke-width":2,"stroke-linecap":"round","stroke-linejoin":"round"};/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */function P(t){return t!=null}function q(t,e={}){var k,h,N,g,m,A,z,v,L,y,B,w,C,b,E;const n=(k=e.attributeNames)!=null?k:{},o=s=>{var u;return(u=n[s])!=null?u:s},i=(N=(h=t.size)!=null?h:t.width)!=null?N:a.width,r=(m=(g=t.size)!=null?g:t.height)!=null?m:a.height,c=(z=(A=t.aliases)==null?void 0:A.filter(s=>typeof s=="string"&&s.trim()!=="").map(s=>`lucide-${s}`))!=null?z:[],f=[...t.name?[`lucide-${t.name}`]:[],...c],l=(L=(v=e.className)==null?void 0:v.split(" ").filter(Boolean))!=null?L:[],x=e.includeDefaultClasses===!1?W(...l):W("lucide",...f,...l),$=e.absoluteStrokeWidth?Number((y=e.strokeWidth)!=null?y:a["stroke-width"])*Number((w=(B=t.size)!=null?B:t.width)!=null?w:a.width)/Number((b=(C=e.size)!=null?C:e.width)!=null?b:a.width):(E=e.strokeWidth)!=null?E:a["stroke-width"];return["svg",{...Object.entries(a).reduce((s,[u,I])=>(s[o(u)]=I,s),{}),..."color"in e&&e.color&&{[o("stroke")]:e.color},..."size"in e&&P(e.size)&&{[o("width")]:e.size,[o("height")]:e.size},..."width"in e&&P(e.width)&&{[o("width")]:e.width},..."height"in e&&P(e.height)&&{[o("height")]:e.height},[o("stroke-width")]:$,...x&&{[o("class")]:x},[o("viewBox")]:`0 0 ${i} ${r}`,...e.hasA11yProp===!1?{[o("aria-hidden")]:"true"}:{},..."attributes"in e&&e.attributes},t.node.map(s=>{const[u,I,p]=s,R=e.nonScalingStroke?{[o("vector-effect")]:"non-scaling-stroke",...I}:I;return p?[u,R,p]:[u,R]})]}/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */function H(t,e={}){return q(t,{...e,attributeNames:{...e.attributeNames,class:"className","stroke-width":"strokeWidth","stroke-linecap":"strokeLinecap","stroke-linejoin":"strokeLinejoin","vector-effect":"vectorEffect"}})}/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */const K=t=>{for(const e in t)if(e.startsWith("aria-")||e==="role"||e==="title")return!0;return!1},Z=d.createContext({}),_=()=>d.useContext(Z),G=d.forwardRef(({color:t,size:e,width:n,height:o,strokeWidth:i,absoluteStrokeWidth:r,nonScalingStroke:c,className:f="",children:l,iconNode:x=[],icon:$={node:x,aliases:[],size:24},...j},k)=>{var w,C,b;const{size:h=24,strokeWidth:N=2,absoluteStrokeWidth:g=!1,nonScalingStroke:m=!1,color:A="currentColor",className:z=""}=(w=_())!=null?w:{},v=!!l||K(j),[L,y,B=[]]=H($,{color:t!=null?t:A,width:(C=n!=null?n:e)!=null?C:h,height:(b=o!=null?o:e)!=null?b:h,strokeWidth:i!=null?i:N,absoluteStrokeWidth:r!=null?r:g,nonScalingStroke:c!=null?c:m,className:W(z,f),hasA11yProp:v,attributes:j});return d.createElement(L,{ref:k,...y},[...B.map(([E,s])=>d.createElement(E,s)),...Array.isArray(l)?l:[l]])});/**
 * @license lucide-react v1.52.0 - ISC
 *
 * This source code is licensed under the ISC license.
 * See the LICENSE file in the root directory of this source tree.
 */function M(t,e=[],n=[]){const o=typeof t=="string"?D(t,e,n):t,i=d.forwardRef(({className:r,...c},f)=>d.createElement(G,{ref:f,icon:o,className:r,...c}));return o.name&&(i.displayName=U(o.name)),i}export{M as c};
