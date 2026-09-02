'use strict';

(function (global) {
    'use strict';

    function asBytes(input) {
        if (input instanceof Uint8Array) return input;
        if (input instanceof ArrayBuffer) return new Uint8Array(input);
        if (ArrayBuffer.isView(input)) return new Uint8Array(input.buffer, input.byteOffset, input.byteLength);
        throw new Error('Legacy .uz decoder requires binary input.');
    }

    function readU32(data, offset) {
        if (offset < 0 || offset + 4 > data.length) return -1;
        return new DataView(data.buffer, data.byteOffset + offset, 4).getUint32(0, true);
    }

    function readI32(data, offset) {
        if (offset < 0 || offset + 4 > data.length) return -1;
        return new DataView(data.buffer, data.byteOffset + offset, 4).getInt32(0, true);
    }

    function readCompactIndex(data, position) {
        if (position >= data.length) return null;
        const first=data[position++];
        const negative=(first & 0x80)!==0;
        let value=first & 0x3f;
        if ((first & 0x40)!==0) {
            let shift=6;
            for (let index=0; index<4; index++) {
                if (position>=data.length) return null;
                const next=data[position++];
                value += (next & 0x7f) * Math.pow(2, shift);
                if ((next & 0x80)===0) break;
                if (index===3) return null;
                shift += 7;
            }
        }
        return {value:negative ? -value : value, position:position};
    }

    function decodeUtf16Le(bytes) {
        if (typeof TextDecoder==='function') {
            try { return new TextDecoder('utf-16le',{fatal:false}).decode(bytes); } catch (ignore) {}
        }
        let result='';
        for (let offset=0; offset+1<bytes.length; offset+=2) {
            const code=bytes[offset] | (bytes[offset+1] << 8);
            if (code===0) break;
            result += String.fromCharCode(code);
        }
        return result;
    }

    function basename(name) {
        const normalized=String(name||'').replace(/\\/g,'/');
        return normalized.split('/').pop() || '';
    }

    function header(input, expectedSignature) {
        const data=asBytes(input);
        const signature=readU32(data,0);
        if (signature!==1234 && signature!==5678) return null;
        if (expectedSignature!==undefined && expectedSignature!==null && signature!==Number(expectedSignature)) return null;

        let position=4;
        const compact=readCompactIndex(data,position);
        if (!compact || compact.value===0) return null;
        position=compact.position;
        let filename='';

        if (compact.value>0) {
            const characters=compact.value;
            if (characters>1024 || position+characters>data.length) return null;
            const raw=data.subarray(position,position+characters);
            position += characters;
            if (!raw.length || raw[raw.length-1]!==0) return null;
            if (typeof TextDecoder==='function') {
                try { filename=new TextDecoder('windows-1252',{fatal:false}).decode(raw.subarray(0,raw.length-1)); } catch (ignore) {}
            }
            if (!filename) {
                for (let index=0; index<raw.length-1; index++) filename += String.fromCharCode(raw[index]);
            }
        } else {
            const characterCount=-compact.value;
            const byteCount=characterCount*2;
            if (characterCount>1024 || position+byteCount>data.length) return null;
            const raw=data.subarray(position,position+byteCount);
            position += byteCount;
            if (raw.length<2 || raw[raw.length-2]!==0 || raw[raw.length-1]!==0) return null;
            filename=decodeUtf16Le(raw.subarray(0,raw.length-2));
        }

        filename=basename(filename);
        if (!filename || position+4>data.length) return null;
        return {offset:position,filename:filename,signature:signature};
    }

    class BitReader {
        constructor(data){ this.data=data; this.position=0; this.length=data.length*8; }
        readBit(){
            if (this.position>=this.length) throw new Error('Unreal redirect Huffman bitstream ended unexpectedly.');
            const position=this.position++;
            return (this.data[position>>3] >> (position & 7)) & 1;
        }
        readByte(){
            let value=0;
            for (let bit=0; bit<8; bit++) value |= this.readBit() << bit;
            return value;
        }
    }

    function huffmanTree(reader,left,right,depth) {
        if (depth>256 || left.length>255) throw new Error('Unreal redirect Huffman table is invalid.');
        if (reader.readBit()===0) return -reader.readByte()-1;
        const index=left.length;
        left.push(0); right.push(0);
        left[index]=huffmanTree(reader,left,right,depth+1);
        right[index]=huffmanTree(reader,left,right,depth+1);
        return index;
    }

    function decodeHuffman(data,limit) {
        const total=readI32(data,0);
        if (total<=0 || total>limit) throw new Error('Unreal redirect Huffman output size is invalid.');
        const reader=new BitReader(data.subarray(4));
        const left=[], right=[];
        const root=huffmanTree(reader,left,right,0);
        const output=new Uint8Array(total);
        for (let position=0; position<total; position++) {
            let node=root;
            while (node>=0) node=reader.readBit()!==0 ? right[node] : left[node];
            output[position]=-node-1;
        }
        const remaining=reader.length-reader.position;
        if (remaining>=8) throw new Error('Unreal redirect Huffman stream contains trailing data.');
        while (reader.position<reader.length) {
            if (reader.readBit()!==0) throw new Error('Unreal redirect Huffman padding is invalid.');
        }
        return output;
    }

    function decodeMtf(data) {
        const list=new Uint16Array(256);
        for (let index=0; index<256; index++) list[index]=index;
        const output=new Uint8Array(data.length);
        for (let position=0; position<data.length; position++) {
            const index=data[position];
            const value=list[index];
            output[position]=value;
            for (let move=index; move>0; move--) list[move]=list[move-1];
            list[0]=value;
        }
        return output;
    }

    function rleOutputLength(data,limit) {
        let count=0, previous=0, total=0;
        for (let position=0; position<data.length; position++) {
            const byte=data[position];
            total++;
            if (byte!==previous) {
                previous=byte; count=1;
            } else if (++count===5) {
                position++;
                if (position>=data.length) throw new Error('Unreal redirect RLE stream is truncated.');
                const runLength=data[position];
                if (runLength<2) throw new Error('Unreal redirect RLE run length is invalid.');
                if (runLength>5) total += runLength-5;
                count=0;
            }
            if (total>limit) throw new Error('Unreal redirect output exceeds the configured limit.');
        }
        return total;
    }

    function decodeRle(data,limit) {
        const total=rleOutputLength(data,limit);
        const output=new Uint8Array(total);
        let outputOffset=0, count=0, previous=0;
        for (let position=0; position<data.length; position++) {
            const byte=data[position];
            output[outputOffset++]=byte;
            if (byte!==previous) {
                previous=byte; count=1;
            } else if (++count===5) {
                position++;
                if (position>=data.length) throw new Error('Unreal redirect RLE stream is truncated.');
                const runLength=data[position];
                if (runLength<2) throw new Error('Unreal redirect RLE run length is invalid.');
                const extra=Math.max(0,runLength-5);
                if (extra>0) {
                    output.fill(byte,outputOffset,outputOffset+extra);
                    outputOffset += extra;
                }
                count=0;
            }
        }
        return output;
    }

    function scanBwt(data,limit) {
        let position=0,total=0,chunks=0;
        while (position<data.length) {
            if (position+12>data.length) throw new Error('Unreal redirect BWT header is truncated.');
            const blockLength=readI32(data,position);
            const first=readI32(data,position+4);
            const last=readI32(data,position+8);
            position += 12;
            if (blockLength<0 || blockLength>0x40000) throw new Error('Unreal redirect BWT block size is invalid.');
            const encodedLength=blockLength+1;
            if (first<0 || first>=encodedLength || last<0 || last>=encodedLength || position+encodedLength>data.length) {
                throw new Error('Unreal redirect BWT block references are invalid.');
            }
            total += blockLength;
            if (total>limit) throw new Error('Unreal redirect BWT output exceeds the configured limit.');
            position += encodedLength;
            chunks++;
        }
        if (chunks===0) throw new Error('Unreal redirect BWT stream is empty.');
        return {total:total,chunks:chunks};
    }

    function decodeBwt(data,limit) {
        const scan=scanBwt(data,limit);
        const output=new Uint8Array(scan.total);
        let position=0, outputOffset=0;
        while (position<data.length) {
            const blockLength=readI32(data,position);
            const first=readI32(data,position+4);
            const last=readI32(data,position+8);
            position += 12;
            const encodedLength=blockLength+1;
            const buffer=data.subarray(position,position+encodedLength);
            position += encodedLength;

            const counts=new Int32Array(257);
            for (let index=0; index<encodedLength; index++) {
                const symbol=index===last ? 256 : buffer[index];
                counts[symbol]++;
            }
            const running=new Int32Array(257);
            let sum=0;
            for (let symbol=0; symbol<257; symbol++) {
                running[symbol]=sum;
                sum += counts[symbol];
                counts[symbol]=0;
            }
            const mapping=new Int32Array(encodedLength);
            for (let index=0; index<encodedLength; index++) {
                const symbol=index===last ? 256 : buffer[index];
                mapping[running[symbol]+counts[symbol]++]=index;
            }
            let index=first;
            for (let decoded=0; decoded<blockLength; decoded++) {
                output[outputOffset++]=buffer[index];
                index=mapping[index];
            }
        }
        return {data:output,chunks:scan.chunks};
    }

    function packageMagic(data) {
        return data.length>=4 && (
            (data[0]===0xc1 && data[1]===0x83 && data[2]===0x2a && data[3]===0x9e)
            || (data[0]===0x9e && data[1]===0x2a && data[2]===0x83 && data[3]===0xc1)
            || (data[0]===0xc2 && data[1]===0x83 && data[2]===0x2a && data[3]===0x9e)
        );
    }

    function decode(input,maxOutputBytes,expectedSignature) {
        const data=asBytes(input);
        const parsed=header(data,expectedSignature);
        if (!parsed) {
            const signature=readU32(data,0);
            throw new Error('The .uz file does not contain a valid Unreal FCodec header'
                + (signature>=0 ? ' (detected signature '+signature+')' : '') + '.');
        }
        const limit=Math.max(1,Math.floor(Number(maxOutputBytes||0)));
        if (!Number.isFinite(limit) || limit<1) throw new Error('Legacy .uz decode limit is invalid.');
        const stageLimit=Math.min(Number.MAX_SAFE_INTEGER,limit+Math.floor(limit/4)+(16*1024*1024));

        let huffman=decodeHuffman(data.subarray(parsed.offset),stageLimit);
        let mtf;
        if (parsed.signature===5678) {
            const rle=decodeRle(huffman,stageLimit);
            huffman=null;
            mtf=decodeMtf(rle);
        } else {
            mtf=decodeMtf(huffman);
            huffman=null;
        }
        const bwt=decodeBwt(mtf,stageLimit);
        mtf=null;
        const output=decodeRle(bwt.data,limit);
        if (!packageMagic(output)) throw new Error('Unreal redirect output does not contain an Unreal package.');

        return {
            data:output,
            decoder:parsed.signature===5678
                ? 'epic-uz-5678-huffman+rle+mtf+bwt+rle'
                : 'epic-uz-1234-huffman+mtf+bwt+rle',
            chunks:bwt.chunks,
            expected_bytes:output.length,
            embedded_filename:parsed.filename,
            wrapper_signature:parsed.signature
        };
    }

    global.UnrealDbLegacyUzDecoder=Object.freeze({
        decode:decode,
        header:header,
        packageMagic:packageMagic
    });
}(self));
